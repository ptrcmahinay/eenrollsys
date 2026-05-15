# E-Enrollment System - Local Setup Guide

This package is a **PHP + MySQL** local application.

## 1. Requirements

- PHP 8.1+ (XAMPP, WAMP, or Laragon is fine)
- MySQL / MariaDB
- Apache
- Browser

## 2. Folder placement

Copy the whole project folder into your local web root.

Examples:

- XAMPP: `C:\xampp\htdocs\e-en-final`
- WAMP: `C:\wamp64\www\e-en-final`
- Laragon: `C:\laragon\www\e-en-final`

## 3. Database setup

1. Open phpMyAdmin.
2. Import the file `enrollmentsystem.sql`.
3. The SQL file will create the database named:
   - `enrollmentsystem`

## 4. Database config

Open:

- `config/db.php`

Default values are:

```php
$dbHost = '127.0.0.1';
$dbPort = '3306';
$dbName = 'enrollmentsystem';
$dbUser = 'root';
$dbPass = '';
```

Change them only if your local MySQL credentials are different.

## 5. Run the app

Open your browser and go to:

- `http://localhost/e-en-final/`

If your folder name is different, update the URL accordingly.

## 6. Sample login accounts

All sample accounts use the same password:

- Password: `Password123!`

### Admin
- Username: `admin1`
- Email: `admin1@example.com`

### Registrar
- Username: `registrar1`
- Email: `registrar1@example.com`

### Department Chair
- Username: `chair.itd`
- Email: `chair.itd@example.com`

### Adviser
- Username: `adviser.itd`
- Email: `adviser.itd@example.com`

### IT Instructor
- Username: `instructor.itd`
- Email: `instructor.itd@example.com`

### ASD Instructor
- Username: `instructor.asd`
- Email: `instructor.asd@example.com`

### Cashier
- Username: `cashier1`
- Email: `cashier1@example.com`

### Student - Regular
- Username: `alice.student`
- Email: `alice.student@example.com`

### Student - Irregular
- Username: `ben.student`
- Email: `ben.student@example.com`

### Student - Already Enrolled
- Username: `carla.student`
- Email: `carla.student@example.com`

## 7. Recommended test flow

### Student flow
- Log in as `alice.student`
- Open **Online Enrollment**
- Submit a regular request
- Check **Enrollment Status**

### Irregular flow
- Log in as `ben.student`
- Open **Online Enrollment**
- Choose irregular subjects
- Subjects with blocked prerequisites should show as not eligible

### Adviser flow
- Log in as `adviser.itd`
- Open **Enrollment Requests**
- Review student grade history
- Approve or reject with remarks

### Department chair flow
- Log in as `chair.itd`
- Open **Enrollment Requests**
- Approve adviser-approved requests
- Open **Assign Adviser** and **Assign Instructor**

### Registrar flow
- Log in as `registrar1`
- Open **Enrollment Queue**
- Approve chair-approved requests
- Open **Students**
- Open a student detail page
- Edit grades and generate Registration Form / COG / Checklist

### Instructor flow
- Log in as `instructor.itd` or `instructor.asd`
- Open **My Subjects**
- Upload a syllabus
- Open **Student List / Grades**
- Encode final grades

### Cashier flow
- Log in as `cashier1`
- Open the dashboard
- Review registrar-approved enrollments and total amount due

## 8. Document downloads

The Registration Form, COG, and Checklist pages are printer-friendly HTML pages.

Use the **Print / Save PDF** button in the browser to save them as PDF locally.

## 9. Notes about the sample data

The SQL file includes sample records for:

- a regular student
- an irregular student with failed / INC prerequisite history
- a student already enrolled in the current term
- requests in adviser, chair, and registrar stages
- an extension tuition example for cashier testing

## 10. If something does not load

Check these first:

- Apache is running
- MySQL is running
- `enrollmentsystem.sql` imported successfully
- `config/db.php` matches your local MySQL credentials
- project folder is inside the correct web root

## 11. Uploads folder

Syllabus files are stored here:

- `uploads/syllabus/`

Make sure Apache/PHP can write to that folder on your machine.
