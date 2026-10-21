/*
 * =====================================================================
 * FabriqPro Production Tracker - Arduino Uno Button Counter Sketch
 * =====================================================================
 *
 * HARDWARE CONNECTIONS:
 * 1. Push Button Leg 1 -> Arduino Uno Pin 2
 * 2. Push Button Leg 2 -> Arduino Uno GND pin
 * (No external resistor required! Uses internal INPUT_PULLUP)
 *
 * 3. USB Cable -> Connect Arduino Uno to your PC USB port
 *
 * HOW IT WORKS:
 * - When button is pressed, Pin 2 connects to GND (LOW).
 * - The sketch debounces the signal (50ms).
 * - Sends "PRESS\n" over Serial at 9600 baud.
 * - Blinks on-board LED (Pin 13) for 100ms on every detected press.
 * =====================================================================
 */

const int BUTTON_PIN = 2;       // Push button pin
const int LED_PIN    = 13;      // Built-in LED for visual feedback

int lastButtonState = HIGH;     // Button is pulled UP, idle state is HIGH
int buttonState;
unsigned long lastDebounceTime = 0;
const unsigned long debounceDelay = 50; // 50 milliseconds debounce

void setup() {
  // Initialize Serial communication at 9600 baud
  Serial.begin(9600);
  
  // Set button pin as INPUT with internal pull-up resistor
  pinMode(BUTTON_PIN, INPUT_PULLUP);
  
  // Set LED pin as output
  pinMode(LED_PIN, OUTPUT);
  digitalWrite(LED_PIN, LOW);

  // Send startup message to Serial
  Serial.println("ARDUINO_READY");
}

void loop() {
  int reading = digitalRead(BUTTON_PIN);

  // If the switch changed due to noise or pressing
  if (reading != lastButtonState) {
    lastDebounceTime = millis();
  }

  if ((millis() - lastDebounceTime) > debounceDelay) {
    // If the button state has changed
    if (reading != buttonState) {
      buttonState = reading;

      // If button state is LOW, it was pressed (since INPUT_PULLUP)
      if (buttonState == LOW) {
        // Visual feedback on built-in LED
        digitalWrite(LED_PIN, HIGH);
        
        // Send signal to PC via USB Serial
        Serial.println("PRESS");
        
        delay(80);
        digitalWrite(LED_PIN, LOW);
      }
    }
  }

  lastButtonState = reading;
}
