import cv2
import torch
from ultralytics import YOLO
import time
import pyodbc
from datetime import datetime
import os
import numpy as np
from collections import Counter
import requests
import easyocr
import tkinter as tk
from tkinter import ttk
import threading
from queue import Queue
import logging
os.environ["YOLO_VERBOSE"] = "False"

logging.getLogger("ultralytics").setLevel(logging.ERROR)

insert_queue = Queue(maxsize=50)

PLC_DB_SERVER='10.19.16.21'
PLC_DB_USERNAME='sql_pre'
PLC_DB_PASSWORD='User@eng1'
PLC_DB_DATABASE='prod_control'
PLC_DB_TABLE='dbo.assy_vision'

barcode_value = "-"
barcode_current = "-"   

# ================= FILTER BARCODE =================
barcode_lock = ""
barcode_lock_time = 0
LOCK_TIMEOUT = 5   # detik

MAX_INSERT = 3

def count_barcode(barcode):
    try:
        conn_local = pyodbc.connect(
            f"DRIVER={{SQL Server}};"
            f"SERVER={PLC_DB_SERVER};"
            f"DATABASE={PLC_DB_DATABASE};"
            f"UID={PLC_DB_USERNAME};"
            f"PWD={PLC_DB_PASSWORD}"
        )
        cursor_local = conn_local.cursor()

        query = f"SELECT COUNT(*) FROM {PLC_DB_TABLE} WHERE barcode=?"
        cursor_local.execute(query, barcode)

        count = cursor_local.fetchone()[0]

        cursor_local.close()
        conn_local.close()

        return count
    except:
        return 0

# ================= OCR =================
reader = easyocr.Reader(['en'], gpu=torch.cuda.is_available())
ocr_text = "-"

conn = pyodbc.connect(
    f"DRIVER={{SQL Server}};"
    f"SERVER={PLC_DB_SERVER};"
    f"DATABASE={PLC_DB_DATABASE};"
    f"UID={PLC_DB_USERNAME};"
    f"PWD={PLC_DB_PASSWORD}"
)
cursor = conn.cursor()

device = 'cuda' if torch.cuda.is_available() else 'cpu'

model_cover = YOLO("Cover_Baru.pt").to(device)
model_aksesoris = YOLO("Item_old.pt").to(device)
model_pole = YOLO("PoleDetection.pt").to(device)

COVER_MIN_CONF = 0.97
AKSESORIS_MIN_CONF = 0.50
POLE_MIN_CONF = 0.80

TYPE_POLE_CLASSES = ["TP_Br", "TP_MF", "TP_Htm"]
STICKER_CLASSES = ["S_Br", "S_Htm"]
EMBOSS_CLASSES = ["E_Br"]
TYPE_BATTERY_CLASSES = ["TB_Br", "TB_MF", "TB_Htm"]
DATECODE_CLASSES = ["R_Br", "R_MF", "R_Htm"]
POLE_OK_CLASSES = ["P_MF","P2_MF","P_Br","P2_Br","P_Htm","P2_Htm"]
POLE_NG_CLASSES = ["NP_MF","NP2_MF"]

# ================= COVER MAPPING =================
COVER_MAPPING = {
    "MF": "MF",
    "Br": "Biru",
    "Htm": "Hitam"
}

UPLOAD_URL = "http://10.19.22.147:8080/ProjectCameraInspection/upload.php"
OFFLINE_FOLDER = "Offline_Image"
os.makedirs(OFFLINE_FOLDER, exist_ok=True)


state = "IDLE"
cover_first_detected_time = None
cover_lost_time = None

WAIT_STABLE_DELAY = 0.5
COVER_LOST_DELAY = 0.7

acc_type_pole = set()
acc_sticker = set()
acc_emboss = set()
acc_type_battery = set()
acc_datecode = set()
acc_pole_ok = set()
acc_pole_ng = set()
acc_cover = Counter()
acc_ocr = Counter()
acc_tb_ocr = Counter()

best_score = 0
best_frame = None
last_inspecting_frame = None
preview_capture = None
# ===== Motion Detection =====
prev_gray = None
stable_frame = None
motion_threshold = 15000

bbox_memory = {
    "sticker": None,
    "pole": None,
    "emboss": None,
    "tb": None,
    "date": None
}

jenis_disp="-"
typepole_disp="-"
sticker_disp="-"
emboss_disp="-"
tb_disp="-"
date_disp="-"
status_disp="-"

# ================= DATECODE MAPPING =================

MAPPING_D1 = {'0':['D','O','Q','U'],'1':['I','J','7'],'2':['S','Z','5'],'3':['B','E','8','9']}
MAPPING_D2 = {'0':['D','O','Q','U'],'1':['I','J'],'2':['Z'],'3':['E'],'4':['A','Y','H'],
              '5':['S'],'6':['G'],'7':['C'],'8':['B'],'9':['P','F']}
MAPPING_D3 = {'0':['D','O','Q','U'],'1':['I','J'],'2':['Z'],'3':['E'],'4':['Y'],
              '5':['S'],'6':['G'],'7':['C'],'8':[],'9':['P','F'],'A':['R'],'B':['H']}
MAPPING_D4 = {'0':['D','O','Q','U'],'1':['I','J'],'2':['Z'],'3':['E'],'4':['A','Y','H'],
              '5':['S'],'6':['G'],'7':['C'],'8':['B'],'9':['P','F']}
MAPPING_D5 = {'A':['R','4'],'B':['D','O','Q','8'],'C':['E','Z','2']}
MAPPING_D6 = {'1':['I','J'],'2':['Z'],'3':['E','B'],'4':['Y'],'5':['S'],'6':['G'],'7':['C']}
MAPPING_D7 = {'D':['B','H','O','Q','U','0','8']}

# ================= TYPE BATTERY OCR MAPPING (TB_Br) =================

TB_MAPPING = {
    0: ['3','4','5','6','7','8','9'],  # digit 1
    1: ['0','5','8'],                  # digit 2
    2: ['B','D'],                      # digit 3
    3: ['2','3'],                      # digit 4
    4: ['1','6'],                      # digit 5
    5: ['R','L']                       # digit 6
}

def db_worker():

    conn_local = pyodbc.connect(
        f"DRIVER={{SQL Server}};"
        f"SERVER={PLC_DB_SERVER};"
        f"DATABASE={PLC_DB_DATABASE};"
        f"UID={PLC_DB_USERNAME};"
        f"PWD={PLC_DB_PASSWORD}"
    )
    cursor_local = conn_local.cursor()

    while True:
        data = insert_queue.get()

        if data is None:
            break

        try:
            (
                created_at, barcode_final, jenis, type_pole,
                sticker, type_battery, emboss, datecode,
                pole, status, local_path
            ) = data

            if cek_server_online():
                time.sleep(0.1)  
                web_path = kirim_gambar_ke_laptop(
                    local_path,
                    type_pole,
                    sticker,
                    type_battery,
                    emboss,
                    datecode
                )
                if web_path is None:
                    web_path = local_path
            else:
                web_path = local_path

            sql = f"""
            INSERT INTO {PLC_DB_TABLE}
            (created_at, barcode, jenis, type_pole, sticker, type_battery, emboss, datecode, Pole, Status, image_path)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
            """

            cursor_local.execute(sql,
                created_at, barcode_final, jenis, type_pole,
                sticker, type_battery, emboss, datecode,
                pole, status, web_path
            )

            cursor_local.execute("""
                INSERT INTO dbo.assy_result (created_at, result)
                VALUES (?, ?)
            """, created_at, status)

            conn_local.commit()

            print(f"INSERT BERHASIL | BARCODE: {barcode_final}")

        except Exception as e:
            print("ERROR WORKER:", e)

        insert_queue.task_done()

threading.Thread(target=db_worker, daemon=True).start()

def map_by_position(c, pos):
    c = c.upper()

    mapping_sets = {
        0: MAPPING_D1,
        1: MAPPING_D2,
        2: MAPPING_D3,
        3: MAPPING_D4,
        4: MAPPING_D5,
        5: MAPPING_D6,
        6: MAPPING_D7,
    }

    # posisi 8-11 menggunakan mapping D2
    if pos in [7,8,9,10]:
        mapping_sets[pos] = MAPPING_D2

    if pos in mapping_sets:
        for key, vals in mapping_sets[pos].items():
            if c == key or c in vals:
                return key

    return ""

def normalize_datecode(text):

    text = text.replace(" ", "").upper()

    result = ""

    for i, c in enumerate(text):

        if i >= 11:
            break

        mapped = map_by_position(c, i)

        if mapped != "":
            result += mapped
        else:
            result += "?"

    return result

def normalize_tb_br(text):

    text = text.replace(" ", "").upper()

    result = ""

    for i, c in enumerate(text):

        if i >= 6:
            break

        if i in TB_MAPPING:

            if c in TB_MAPPING[i]:
                result += c
            else:
                result += "?"

    return result

def draw_ui(stream, preview):

    h,w,_ = stream.shape
    panel = np.zeros((h,400,3),dtype=np.uint8)
    panel[:]=(10,25,60)

    # ================= PREVIEW =================
    if preview is not None:
        pv=cv2.resize(preview,(360,220))
    else:
        pv=np.zeros((220,360,3),dtype=np.uint8)

    panel[20:240,20:380]=pv
    cv2.rectangle(panel,(20,20),(380,240),(255,255,255),2)

    # ================= RESULT BOX =================
    cv2.rectangle(panel,(20,370),(380,520),(255,255,255),-1)

    y=400
    gap=35

    # ================= STATUS OK =================
    if status_disp == "OK":

        cv2.putText(panel,"RESULT",(120,365),
                    cv2.FONT_HERSHEY_SIMPLEX,1,(0,150,0),2)

        cv2.putText(panel,"OK",(120,470),
                    cv2.FONT_HERSHEY_SIMPLEX,3,(0,200,0),5)

    # ================= STATUS NG =================
    elif status_disp == "NG":

        cv2.putText(panel,"NG Result",(120,365),
                    cv2.FONT_HERSHEY_SIMPLEX,1,(0,0,255),2)

        if sticker_disp == "NOK":
            cv2.putText(panel,"Sticker : NOK",(40,y),
                        cv2.FONT_HERSHEY_SIMPLEX,0.6,(0,0,255),2)
            y+=gap

        if emboss_disp == "NOK":
            cv2.putText(panel,"Emboss : NOK",(40,y),
                        cv2.FONT_HERSHEY_SIMPLEX,0.6,(0,0,255),2)
            y+=gap

        if tb_disp == "NOK":
            cv2.putText(panel,"Type Battery : NOK",(40,y),
                        cv2.FONT_HERSHEY_SIMPLEX,0.6,(0,0,255),2)
            y+=gap

        if date_disp == "NOK":
            cv2.putText(panel,"Datecode : NOK",(40,y),
                        cv2.FONT_HERSHEY_SIMPLEX,0.6,(0,0,255),2)
            y+=gap

    return panel

cap = cv2.VideoCapture(0)

# gunakan resolusi HD kamera
cap.set(cv2.CAP_PROP_FRAME_WIDTH,1280)
cap.set(cv2.CAP_PROP_FRAME_HEIGHT,720)

# meningkatkan kualitas stream
cap.set(cv2.CAP_PROP_FOURCC, cv2.VideoWriter_fourcc(*'MJPG'))

# aktifkan autofocus jika kamera support
cap.set(cv2.CAP_PROP_AUTOFOCUS,1)

fullscreen = True

cv2.namedWindow("Battery Inspection", cv2.WINDOW_NORMAL)

if fullscreen:
    cv2.setWindowProperty("Battery Inspection",
                          cv2.WND_PROP_FULLSCREEN,
                          cv2.WINDOW_FULLSCREEN)



def kirim_gambar_ke_laptop(local_path, type_pole, sticker, type_battery, emboss, datecode):

    for i in range(3):

        try:
            data = {
                'type_pole': type_pole,
                'sticker': sticker,
                'type_battery': type_battery,
                'emboss': emboss,
                'datecode': datecode
            }

            with open(local_path, 'rb') as f:
                files = {'image': f}

                r = requests.post(UPLOAD_URL, files=files, data=data, timeout=20)

            print("RESPONSE:", r.text)

            if r.status_code == 200 and "http" in r.text:
                print(f"UPLOAD SUCCESS (try {i+1})")
                return r.text

        except Exception as e:
            print(f"TRY {i+1} GAGAL:", e)
            time.sleep(1)


    return None
def cek_server_online():
    try:
        r = requests.get("http://10.19.22.147:8080", timeout=2)
        return True
    except:
        return False

def is_frame_stable(frame):
    global prev_gray

    gray = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)

    if prev_gray is None:
        prev_gray = gray
        return False

    diff = cv2.absdiff(prev_gray, gray)
    thresh = cv2.threshold(diff, 25, 255, cv2.THRESH_BINARY)[1]

    motion_score = np.sum(thresh)

    prev_gray = gray

    if motion_score < motion_threshold:
        return True
    else:
        return False

def read_datecode(frame, bbox):

    try:
        x1,y1,x2,y2 = bbox

        roi = frame[y1:y2, x1:x2]

        if roi.size == 0:
            return "-"

        # preprocessing
        gray = cv2.cvtColor(roi, cv2.COLOR_BGR2GRAY)
        gray = cv2.resize(gray, None, fx=2, fy=2, interpolation=cv2.INTER_CUBIC)
        gray = cv2.GaussianBlur(gray,(3,3),0)

        result = reader.readtext(gray)

        if len(result) > 0:
            text = result[0][1]
            text = text.replace(" ", "")
            return text

        return "-"

    except:
        return "-"

def read_tb_br(frame, bbox):

    try:

        x1,y1,x2,y2 = bbox

        roi = frame[y1:y2, x1:x2]

        if roi.size == 0:
            return "-"

        # hanya ambil bagian atas (hindari angka bawah)
        h = roi.shape[0]
        roi = roi[0:int(h*0.55), :]

        gray = cv2.cvtColor(roi, cv2.COLOR_BGR2GRAY)
        gray = cv2.resize(gray, None, fx=2, fy=2, interpolation=cv2.INTER_CUBIC)
        gray = cv2.GaussianBlur(gray,(3,3),0)

        result = reader.readtext(gray)

        if len(result) > 0:

            text = result[0][1]
            text = text.replace(" ", "")

            return normalize_tb_br(text)

        return "-"

    except:
        return "-"



def run_barcode_gui():
    global barcode_value

    def on_enter(event):
        global barcode_value, barcode_current 

        barcode = entry.get().strip()
        if barcode:
            barcode_value = barcode
            barcode_current = barcode  

            # simpan ke tabel barcode
            try:
                conn_bar = pyodbc.connect(
                    f"DRIVER={{SQL Server}};SERVER={PLC_DB_SERVER};DATABASE={PLC_DB_DATABASE};UID={PLC_DB_USERNAME};PWD={PLC_DB_PASSWORD}"
                )
                cur_bar = conn_bar.cursor()

                cur_bar.execute("""
                    INSERT INTO dbo.assy_barcode_line5 (created_at, barcode)
                    VALUES (?, ?)
                """, datetime.now(), barcode)

                conn_bar.commit()
                cur_bar.close()
                conn_bar.close()

                print("BARCODE MASUK:", barcode)

            except Exception as e:
                print("ERROR BARCODE:", e)

            listbox.insert(tk.END, barcode)
            entry.delete(0, tk.END)

    root = tk.Tk()
    root.title("SCAN BARCODE")
    root.geometry("350x150")

    ttk.Label(root, text="SCAN BARCODE", font=("Arial", 12)).pack(pady=5)

    entry = ttk.Entry(root, font=("Arial", 16), justify='center')
    entry.pack(padx=10, fill='x')
    entry.focus()

    entry.bind('<Return>', on_enter)

    listbox = tk.Listbox(root, height=3)
    listbox.pack(padx=10, pady=5, fill='both', expand=True)

    root.mainloop()

threading.Thread(target=run_barcode_gui, daemon=True).start()

while True:
    ret, frame = cap.read()

    if not ret:
        continue   # ?? jangan break biar program tidak mati

    if frame is None:
        continue   # ?? INI yang kamu tanya

    frame_clean = frame.copy()
    frame_resized = frame
    annotated = frame.copy()

    results_cover = model_cover(frame_resized, imgsz=640, conf=0.25, device=device, verbose=False)

    cover_detected=False
    current_cover="-"
    cover_count=0

    for box in results_cover[0].boxes:
        conf=float(box.conf[0])
        if conf>=COVER_MIN_CONF:
            cls=int(box.cls[0])
            current_cover=model_cover.names[cls]
            cover_detected=True
            cover_count+=1
            x1,y1,x2,y2=map(int,box.xyxy[0])
            cv2.rectangle(annotated,(x1,y1),(x2,y2),(0,255,0),2)
            cv2.putText(annotated,f"{current_cover} {conf:.2f}",
                        (x1,y1-10),
                        cv2.FONT_HERSHEY_SIMPLEX,0.5,(0,255,0),2)

    if state=="IDLE":
        if cover_detected:
            cover_first_detected_time=time.time()
            state="WAIT_STABLE"

    elif state=="WAIT_STABLE":
        if cover_detected:
            if time.time()-cover_first_detected_time>=WAIT_STABLE_DELAY:
                state="INSPECTING"
        else:
            state="IDLE"

    elif state=="INSPECTING":

        if cover_detected:

            cover_lost_time=None
            acc_cover[current_cover] += 1

            results_aksesoris = model_aksesoris(frame_resized, imgsz=640, conf=0.25, device=device, verbose=False)
            item_count=0

            for box in results_aksesoris[0].boxes:
                conf=float(box.conf[0])
                if conf>=AKSESORIS_MIN_CONF:

                    cls=int(box.cls[0])
                    label=model_aksesoris.names[cls]
                    item_count+=1

                    x1,y1,x2,y2=map(int,box.xyxy[0])

                    if label in STICKER_CLASSES:

                        acc_sticker.add(label)
                        bbox_memory["sticker"] = (x1,y1,x2,y2)

                    elif label in EMBOSS_CLASSES:
                        acc_emboss.add(label)
                        bbox_memory["emboss"] = (x1,y1,x2,y2)

                    elif label in TYPE_BATTERY_CLASSES:

                        acc_type_battery.add(label)
                        bbox_memory["tb"] = (x1,y1,x2,y2)

                        tb_raw = read_tb_br(frame_resized, (x1,y1,x2,y2))

                        if tb_raw != "-":
                            acc_tb_ocr[tb_raw] += 1

                        cv2.putText(annotated,f"TB:{tb_raw}",
                                    (x1,y2+40),
                                    cv2.FONT_HERSHEY_SIMPLEX,
                                    0.6,(0,255,255),2)

                    elif label in DATECODE_CLASSES:
                        acc_datecode.add(label)
                        bbox_memory["date"] = (x1,y1,x2,y2)

                        ocr_raw = read_datecode(frame_resized, (x1,y1,x2,y2))
                        ocr_text = normalize_datecode(ocr_raw)

                        if len(ocr_text) >= 8:   # hanya simpan OCR yang cukup panjang
                            acc_ocr[ocr_text] += 1


                    elif label in TYPE_POLE_CLASSES:
                        acc_type_pole.add(label)

                    cv2.rectangle(annotated,(x1,y1),(x2,y2),(0,255,0),2)
                    cv2.putText(annotated,f"{label} {conf:.2f}",
                                (x1,y1-10),
                                cv2.FONT_HERSHEY_SIMPLEX,0.5,(0,255,0),2)

            # ================= POLE DETECTION =================

            results_pole = model_pole(frame_resized, imgsz=640, conf=0.25, device=device, verbose=False)

            for box in results_pole[0].boxes:

                conf = float(box.conf[0])

                if conf >= POLE_MIN_CONF:

                    cls = int(box.cls[0])
                    label = model_pole.names[cls]

                    x1,y1,x2,y2 = map(int,box.xyxy[0])

                    if label in POLE_OK_CLASSES:
                        acc_pole_ok.add(label)

                    elif label in POLE_NG_CLASSES:
                        acc_pole_ng.add(label)

                    cv2.rectangle(annotated,(x1,y1),(x2,y2),(255,255,0),2)
                    cv2.putText(annotated,f"{label} {conf:.2f}",
                                (x1,y1-10),
                                cv2.FONT_HERSHEY_SIMPLEX,0.5,(255,255,0),2)

            # ================= SAVE LAST FRAME =================
            if is_frame_stable(frame_resized):
                stable_frame = frame_clean.copy()  

            last_inspecting_frame = frame_clean.copy()   
            best_frame = last_inspecting_frame

        else:

            if cover_lost_time is None:
                cover_lost_time=time.time()

            elif time.time()-cover_lost_time>=COVER_LOST_DELAY:

                if last_inspecting_frame is not None:

                    # ===== gunakan frame stabil jika ada =====
                    if stable_frame is not None:
                        final_frame = stable_frame.copy()
                    else:
                        final_frame = last_inspecting_frame.copy()

                    best_frame = final_frame.copy()
                    preview_capture = final_frame.copy()

                created_at = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
                folder_date = datetime.now().strftime("%Y-%m-%d")
                save_folder = os.path.join("Hasil", folder_date)
                os.makedirs(save_folder, exist_ok=True)

                filename = f"Hasil_Image{datetime.now().strftime('%H%M%S')}.jpg"
                #image_path = os.path.join(save_folder, filename)

                #if best_frame is not None:
                    #cv2.imwrite(image_path, best_frame)

                local_path = os.path.join(save_folder, filename)

                if best_frame is not None:
                    cv2.imwrite(local_path, best_frame)
                    #small = cv2.resize(best_frame, (640,480))   # ?? compress dulu
                    #cv2.imwrite(local_path, small)


                #if web_path is None:
                    #print("UPLOAD GAGAL - INSERT LOCAL PATH")
                    #web_path = local_path

                # ================= MODE ONLINE / OFFLINE =================

                if len(acc_cover) > 0:
                    raw_cover = acc_cover.most_common(1)[0][0]
                else:
                    raw_cover = "UNKNOWN"
                    
                jenis = COVER_MAPPING.get(raw_cover, raw_cover)
                type_pole = "L-Type" if len(acc_type_pole)>0 else "R-Type"
                
                if jenis == "MF":
                    sticker = "-"
                    emboss = "-"
                else:
                    sticker = "OK" if len(acc_sticker)>0 else "NOK"
                    emboss = "OK" if len(acc_emboss)>0 else "NOK"

                # ================= TYPE BATTERY =================

                type_battery = "NOK"

                # cek apakah class TB terdeteksi
                if len(acc_type_battery) > 0:

                    # jika ada deteksi tapi OCR tidak menghasilkan apapun
                    if len(acc_tb_ocr) == 0:

                        type_battery = "OK"

                    else:

                        valid_tb = {}

                        for code, count in acc_tb_ocr.items():

                            # hanya ambil OCR valid
                            if len(code) == 6 and "?" not in code:
                                valid_tb[code] = count

                        # jika ada hasil valid ? pakai voting terbanyak
                        if len(valid_tb) > 0:

                            type_battery = max(valid_tb, key=valid_tb.get)

                        # jika semua OCR invalid
                        else:

                            type_battery = "OK"

                # jika tidak ada deteksi TB sama sekali
                else:

                    type_battery = "NOK"

                # ================= DATECODE =================

                datecode = "NOK"

                # cek apakah class datecode terdeteksi
                if len(acc_datecode) > 0:

                    # jika ada deteksi tapi belum ada OCR
                    if len(acc_ocr) == 0:

                        datecode = "OK"

                    else:

                        valid_ocr = {}

                        for code, count in acc_ocr.items():

                            # hanya ambil OCR valid
                            if len(code) == 11 and "?" not in code:
                                valid_ocr[code] = count

                        # jika ada hasil valid
                        if len(valid_ocr) > 0:

                            datecode = max(valid_ocr, key=valid_ocr.get)

                        # jika semua hasil OCR tidak valid
                        else:

                            datecode = "OK"

                # jika tidak ada deteksi class sama sekali
                else:

                    datecode = "NOK"

                # ================= STATUS =================
                if type_battery != "NOK" and datecode != "NOK":
                    status_text = "OK"
                    status = 0
                else:
                    status_text = "NG"
                    status = 1
                
                # ================= POLE RESULT =================

                if len(acc_pole_ng) > 0:
                    pole = "NG"
                elif len(acc_pole_ok) > 0:
                    pole = "OK"
                else:
                    pole = "NG"

                # ================= VALIDASI BARCODE =================
                current_time = time.time()
                barcode_final = "-"
                
                # ================= BARCODE FINAL (ANTI TERTIMPA) =================
                barcode_final = barcode_current

                if barcode_final == "-":
                    print("? BELUM ADA BARCODE")

                # ================= INSERT DATA (WAJIB MASUK) =================

                # jika tidak ada barcode, tetap simpan dengan "-"
                if barcode_final == "-":
                    barcode_final = "-"

                sql = f"""
                INSERT INTO {PLC_DB_TABLE}
                (created_at, barcode, jenis, type_pole, sticker, type_battery, emboss, datecode, Pole, Status, image_path)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)
                """

                insert_queue.put((
                    created_at,
                    barcode_final,
                    jenis,
                    type_pole,
                    sticker,
                    type_battery,
                    emboss,
                    datecode,
                    pole,
                    status,
                    local_path
                ))

                print(f"? DATA TERSIMPAN | BARCODE: {barcode_final}")

                # reset barcode setelah proses
                
                barcode_current = "-"   # ?? RESET SETELAH DIPAKAI

                # ================= DISPLAY =================
                jenis_disp=jenis
                typepole_disp=type_pole
                sticker_disp=sticker
                emboss_disp=emboss
                tb_disp=type_battery
                date_disp=datecode
                status_disp = status_text

                acc_cover.clear()
                acc_type_pole.clear()
                acc_sticker.clear()
                acc_emboss.clear()
                acc_type_battery.clear()
                acc_tb_ocr.clear()
                acc_datecode.clear()
                acc_pole_ok.clear()
                acc_pole_ng.clear()
                acc_ocr.clear()
                ocr_text = "-"
                
                best_score=0
                best_frame=None
                stable_frame=None
                prev_gray=None

                state="IDLE"
                cover_lost_time=None
                last_inspecting_frame = None
    
                bbox_memory = {
                    "sticker": None,
                    "pole": None,
                    "emboss": None,
                    "tb": None,
                    "date": None
                }

    panel=draw_ui(annotated,preview_capture)
    display=cv2.hconcat([annotated,panel])

    screen_w=1920
    screen_h=1080
    h,w=display.shape[:2]
    scale=min(screen_w/w,screen_h/h)

    resized=cv2.resize(display,(int(w*scale),int(h*scale)))
    canvas=np.zeros((screen_h,screen_w,3),dtype=np.uint8)

    x_offset=(screen_w-resized.shape[1])//2
    y_offset=(screen_h-resized.shape[0])//2

    canvas[y_offset:y_offset+resized.shape[0],
           x_offset:x_offset+resized.shape[1]]=resized

    cv2.imshow("Battery Inspection",canvas)

    key=cv2.waitKey(1)&0xFF

    # tombol F untuk fullscreen / minimize
    if key == ord("f"):
        fullscreen = not fullscreen

        if fullscreen:
            cv2.setWindowProperty("Battery Inspection",
                                  cv2.WND_PROP_FULLSCREEN,
                                  cv2.WINDOW_FULLSCREEN)
        else:
            cv2.setWindowProperty("Battery Inspection",
                                  cv2.WND_PROP_FULLSCREEN,
                                  cv2.WINDOW_NORMAL)

    # exit
    if key==ord("q") or key==27:
        break

cap.release()
cv2.destroyAllWindows()


