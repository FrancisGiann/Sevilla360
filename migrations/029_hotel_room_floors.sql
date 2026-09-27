-- Optional physical floor label for each hotel room unit.
ALTER TABLE hotel_rooms
    ADD COLUMN IF NOT EXISTS floor_label VARCHAR(80) NULL AFTER room_number;
