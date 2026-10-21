# Suguna Project - FabriqPro Production Tracker

FabriqPro is a garment manufacturing production and piece-rate tracking web application with real-time workstation monitoring, worker leaderboard, unit management, and hardware IoT counter integration (Arduino Uno).

---

## 🚀 Features

- **Real-Time Workstation & Piece Entry**: Track piece logs per worker, operation, shift, and work unit.
- **Interactive Dashboards & Leaderboard**: Visual performance analytics, top performers, and production outputs.
- **Role-Based Access Control**: Secure login and roles for Admin, Supervisor, and Worker accounts.
- **Unit & Category Management**: Dynamic configuration of production units, product categories, and piece-rate tariffs.
- **Arduino Hardware Counter Integration**: Real-time push button counter integration with an Arduino Uno via USB serial bridge (`arduino_bridge.py`).
- **RESTful API**: Comprehensive API endpoints for frontend-backend communication and external integrations.

---

## 🛠️ Tech Stack

- **Backend**: PHP (PDO MySQL), Python (Serial bridge for hardware)
- **Frontend**: Vanilla JavaScript (ES6+), Modern CSS3 with responsive glassmorphism design
- **Database**: MySQL (XAMPP / MariaDB support on port 3306 or 3308)
- **Hardware**: Arduino Uno with tactile push button

---

## 📦 Installation & Setup

### 1. Prerequisites
- **XAMPP / WAMP / LAMP** with Apache & MySQL
- **Python 3.x** with `pyserial` and `requests` (optional, for Arduino hardware integration)

### 2. Setup Database
1. Start Apache and MySQL in your XAMPP Control Panel.
2. The application automatically initializes the `fabriqpro_db` database and its required tables on first access via [db.php](file:///c:/Users/DELL/OneDrive/Pictures/project%20%285%29/project/db.php).

### 3. Run the Application
Place the project inside your web server root (e.g., `htdocs/`) or run via PHP built-in server:
```bash
php -S localhost:8000
```
Then navigate to:
```
http://localhost:8000/
```

### 4. Hardware Counter Setup (Optional)
Refer to [ARDUINO_SETUP.md](file:///c:/Users/DELL/OneDrive/Pictures/project%20%285%29/project/ARDUINO_SETUP.md) for complete circuit wiring and Arduino Uno setup instructions.

---

## 📄 License
This project is proprietary and confidential.
