-- Spolujízda – databázové schéma (MySQL)

CREATE TABLE event (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    location VARCHAR(255) NOT NULL COMMENT 'Cílové místo (adresa ubytování)',
    date_from DATETIME NOT NULL,
    date_to DATETIME NOT NULL,
    description TEXT DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ride (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id INT UNSIGNED NOT NULL,
    driver_name VARCHAR(255) NOT NULL,
    driver_phone VARCHAR(50) DEFAULT NULL,
    driver_email VARCHAR(255) NOT NULL,
    departure_city VARCHAR(255) NOT NULL COMMENT 'Odkud',
    departure_place VARCHAR(255) DEFAULT NULL COMMENT 'Přesné místo odjezdu',
    route_via VARCHAR(255) DEFAULT NULL COMMENT 'Místa na trase (přes)',
    departure_time DATETIME NOT NULL,
    total_seats TINYINT UNSIGNED NOT NULL DEFAULT 4,
    note TEXT DEFAULT NULL,
    distance_km SMALLINT UNSIGNED DEFAULT NULL COMMENT 'Přibližná jednosměrná vzdálenost v km (pro výpočet CO2)',
    direction ENUM('there', 'back') NOT NULL DEFAULT 'there',
    edit_token VARCHAR(64) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES event(id) ON DELETE CASCADE,
    INDEX idx_ride_event (event_id),
    INDEX idx_ride_direction (direction)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE passenger (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ride_id INT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(50) NOT NULL,
    departure_city VARCHAR(255) NOT NULL,
    pickup_note VARCHAR(500) DEFAULT NULL,
    edit_token VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ride_id) REFERENCES ride(id) ON DELETE CASCADE,
    INDEX idx_passenger_ride (ride_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ride_request (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id INT UNSIGNED NOT NULL,
    name VARCHAR(255) DEFAULT NULL,
    phone VARCHAR(50) DEFAULT NULL,
    email VARCHAR(255) NOT NULL COMMENT 'Pro notifikaci o nové jízdě',
    departure_city VARCHAR(255) DEFAULT NULL,
    preferred_time DATETIME DEFAULT NULL,
    direction ENUM('there', 'back') NOT NULL DEFAULT 'there',
    note TEXT DEFAULT NULL,
    edit_token VARCHAR(64) NOT NULL,
    is_fulfilled TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES event(id) ON DELETE CASCADE,
    INDEX idx_request_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

