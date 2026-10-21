# 🔌 FabriqPro - Arduino Uno Push Button Counter Setup Guide

This guide walks you through connecting your **Arduino Uno** with a **push button on a breadboard** to automatically increment piece counts one-by-one (+1) in your **FabriqPro** web application.

---

## 🛠️ Required Hardware
1. **Arduino Uno** (connected via USB cable to your PC)
2. **Push Button** (tactile momentary switch)
3. **Breadboard**
4. **2x Jumper Wires** (male-to-male)

---

## 📐 Circuit Wiring (Super Simple - No Resistor Needed!)

The Arduino Uno has built-in internal pull-up resistors (`INPUT_PULLUP`), so you do **NOT** need any external resistors:

```
    Breadboard
   +-----------+
   |  [Button] |
   |  (1)   (2)|
   +---+-----+--+
       |     |
       |     +--------> Arduino GND pin
       |
       +--------------> Arduino Digital Pin 2
```

1. Insert your **push button** across the center divider of your breadboard.
2. Connect **one terminal/leg** of the button to **Digital Pin 2** on the Arduino Uno.
3. Connect the **opposite terminal/leg** of the button to any **GND** pin on the Arduino Uno.
4. Plug the Arduino Uno into your PC with the USB cable.

---

## 💻 Step 1: Upload Sketch to Arduino Uno

1. Open the [Arduino IDE](https://www.arduino.cc/en/software).
2. Open the file: [`arduino_sketch.ino`](arduino_sketch.ino) in your project directory.
3. Select **Tools > Board > Arduino Uno**.
4. Select **Tools > Port > COM...** (choose your Arduino's COM port, e.g., COM3, COM4).
5. Click **Upload** (arrow icon).
6. Once uploaded, the built-in LED (Pin 13) will blink whenever you press the button!

> ⚠️ **Note:** Close the Arduino IDE **Serial Monitor** after verifying, because the Python bridge needs exclusive access to the COM port.

---

## 🐍 Step 2: Run the Python Serial Bridge

The Python bridge reads button clicks from the USB serial port and sends them to your web app in real time.

1. Open PowerShell or Command Prompt in `d:\project`:
   ```powershell
   python arduino_bridge.py
   ```
2. The script will automatically discover your Arduino COM port and start listening.
   *(You can also specify the port directly, e.g., `python arduino_bridge.py COM3`)*
3. **Simulation Mode** (if hardware is not plugged in yet):
   ```powershell
   python arduino_bridge.py --simulate
   ```

---

## 🌐 Step 3: Open the Web Page and Start Counting

1. Open your browser to: **[http://localhost:8000/arduino.php](http://localhost:8000/arduino.php)**
   *(Or click **Arduino Counter** in the top navigation bar)*
2. Under **Session Configuration**:
   - **Select Worker:** Choose the worker who is producing pieces.
   - **Select Material:** Choose the cloth/material being stitched.
   - **Shift & Date:** Pre-filled with today's date & Day Shift.
   - **Pieces per press:** Default is **1** (can be configured to +1, +5, +10, etc.).
3. Click **"Start Arduino Session"**.
4. **Press the push button on your breadboard!**
   - Each press triggers **+1 piece** in real-time.
   - The large counter updates dynamically with an animation.
   - The piece count is recorded directly into the database via `api.php`.
   - The press log displays timestamps and total counts.
   - The overall worker statistics and leaderboard update automatically.
