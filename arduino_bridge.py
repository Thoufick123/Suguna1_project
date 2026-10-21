"""
arduino_bridge.py - USB Serial Bridge between Arduino Uno and FabriqPro
----------------------------------------------------------------------
Listens on the Arduino COM port and notifies the FabriqPro web server
whenever the push button is pressed.

Usage:
  python arduino_bridge.py            (auto-detects COM port)
  python arduino_bridge.py COM3       (specify COM port manually)
  python arduino_bridge.py --simulate (press ENTER to simulate button presses)
"""

import sys
import time
import urllib.request
import urllib.parse
import json

try:
    import serial
    import serial.tools.list_ports
except ImportError:
    print("[ERROR] pyserial is not installed.")
    print("Please run: pip install pyserial")
    sys.exit(1)

import os
DEFAULT_SERVER_URL = os.environ.get("FABRIQ_SERVER_URL", "http://localhost/project/arduino_bridge.php")
BAUD_RATE = 9600

def list_com_ports():
    ports = list(serial.tools.list_ports.comports())
    return [(p.device, p.description) for p in ports]

def send_press_to_server(url, count=1):
    try:
        full_url = f"{url}?action=press&count={count}"
        req = urllib.request.Request(full_url, method="GET")
        with urllib.request.urlopen(req, timeout=3) as resp:
            data = json.loads(resp.read().decode('utf-8'))
            return True, data
    except Exception as e:
        return False, str(e)

def run_simulation(server_url):
    print("=" * 60)
    print(" SIMULATION MODE ACTIVATED (No physical Arduino required)")
    print(f" Target Web Server: {server_url}")
    print(" Press [ENTER] to simulate a push button press (+1)")
    print(" Type 'q' and press [ENTER] to exit")
    print("=" * 60)
    
    count = 0
    while True:
        try:
            line = input("\n[SIMULATOR] Press ENTER to trigger button press: ")
            if line.strip().lower() == 'q':
                break
            count += 1
            now = time.strftime("%H:%M:%S")
            ok, res = send_press_to_server(server_url, 1)
            if ok:
                total = res.get('total_presses', count)
                print(f"[{now}] [OK] Button Click Sent! Total registered by bridge: {total}")
            else:
                print(f"[{now}] [FAIL] Server error: {res}")
        except KeyboardInterrupt:
            print("\nExiting simulation.")
            break

def main():
    print("=" * 60)
    print("   FabriqPro - Arduino Uno USB Serial Bridge")
    print("=" * 60)
    
    server_url = DEFAULT_SERVER_URL
    
    # Check for simulation flag
    if "--simulate" in sys.argv or "-s" in sys.argv:
        run_simulation(server_url)
        return

    # Check command-line port
    target_port = None
    for arg in sys.argv[1:]:
        if arg.upper().startswith("COM") or "/dev/" in arg:
            target_port = arg
            break

    # Discover COM ports
    available_ports = list_com_ports()
    
    if not target_port:
        if not available_ports:
            print("[WARNING] No USB COM ports detected!")
            print("Please make sure your Arduino Uno is connected via USB cable.")
            print("")
            choice = input("Would you like to run in SIMULATION mode instead? (y/n): ").strip().lower()
            if choice == 'y':
                run_simulation(server_url)
                return
            else:
                port_input = input("Enter COM port manually (e.g. COM3): ").strip()
                if not port_input:
                    sys.exit(1)
                target_port = port_input
        elif len(available_ports) == 1:
            target_port = available_ports[0][0]
            print(f"[+] Found single COM port: {target_port} ({available_ports[0][1]})")
        else:
            print("[+] Multiple COM ports found:")
            for i, (port, desc) in enumerate(available_ports):
                print(f"    [{i + 1}] {port} - {desc}")
            sel = input(f"Select port [1-{len(available_ports)}] (default 1): ").strip()
            idx = int(sel) - 1 if sel.isdigit() and 1 <= int(sel) <= len(available_ports) else 0
            target_port = available_ports[idx][0]

    print(f"\n[*] Connecting to Arduino on {target_port} @ {BAUD_RATE} baud...")
    
    try:
        ser = serial.Serial(target_port, BAUD_RATE, timeout=1)
        time.sleep(2)  # Allow Arduino reset cycle to complete
        ser.reset_input_buffer()
        print(f"[OK] Connected to {target_port} successfully!")
        print(f"[*] Forwarding button presses to: {server_url}")
        print("[*] Press Ctrl+C in this terminal to stop.")
        print("-" * 60)
    except Exception as e:
        print(f"[ERROR] Failed to open {target_port}: {e}")
        print("\nTroubleshooting tips:")
        print("1. Close Arduino IDE's Serial Monitor if it is open (only 1 app can use a COM port at a time).")
        print("2. Check Windows Device Manager -> Ports (COM & LPT) to verify port number.")
        print("3. Try running: python arduino_bridge.py --simulate")
        sys.exit(1)

    press_count = 0
    try:
        while True:
            if ser.in_waiting > 0:
                raw_line = ser.readline().decode('utf-8', errors='ignore').strip()
                if not raw_line:
                    continue

                if "PRESS" in raw_line.upper():
                    press_count += 1
                    timestamp = time.strftime("%H:%M:%S")
                    ok, res = send_press_to_server(server_url, 1)
                    if ok:
                        total_p = res.get('total_presses', press_count)
                        print(f"[{timestamp}] [BUTTON PRESSED] -> +1 Piece sent to FabriqPro! (Total: {total_p})")
                    else:
                        print(f"[{timestamp}] [BUTTON PRESSED] -> Error reaching web server: {res}")
                elif "READY" in raw_line.upper():
                    print(f"[{time.strftime('%H:%M:%S')}] Arduino Uno initialized: {raw_line}")
                else:
                    print(f"[{time.strftime('%H:%M:%S')}] [SERIAL] {raw_line}")
            time.sleep(0.02)
    except KeyboardInterrupt:
        print("\n[*] Stopping Arduino Bridge. Goodbye!")
    finally:
        ser.close()

if __name__ == "__main__":
    main()
