# Sevilla360

Sevilla360 is a web-based booking and virtual showroom platform developed for M.I. Sevilla Resort. It combines an interactive 360-degree tour with reservation features for guests and administrative tools for staff.

Status: work in progress.

## Overview

The application provides a digital resort experience that allows visitors to explore the property virtually, check availability, and submit bookings through a browser-based interface.

## Key Features

- Interactive 360-degree virtual showroom powered by Panolens.js.
- Clickable tags and hotspots for navigating resort areas.
- Online booking flow for guests.
- Separate user and admin interfaces.
- Responsive layouts for desktop and mobile devices.

## Technology Stack

- PHP
- MySQL
- HTML, CSS, JavaScript
- Panolens.js

## Requirements

- PHP environment with a web server such as Apache or Nginx.
- MySQL database.
- A browser for local testing.

## Local Setup

1. Clone the repository into your web server directory.
2. Create a MySQL database named `sevilla360` or update the database name in `config/db_connect.php`.
3. Update the database credentials in `config/db_connect.php` to match your local environment.
4. Import the project database schema and seed data if available.
5. Open `index.php` in your browser through the configured local server.

## Project Structure

- `index.php` - main landing page.
- `booking.php` - booking interface.
- `showroom.php` - virtual showroom experience.
- `user_dashboard.php` - user dashboard.
- `admin_dashboard.php` - admin dashboard entry point.
- `actions/` - authentication and booking processing scripts.
- `includes/` - shared layout components and page sections.
- `assets/` - CSS and JavaScript assets.
- `config/` - database connection and configuration.

## Notes

- The project is currently under active development.
- Some setup details may change as the system matures.

## Author

Created by Francis Giann Mendevil Empleo for portfolio purposes.
