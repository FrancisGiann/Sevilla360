-- Optional saved camera framing for each panorama and direction for nav pins.
ALTER TABLE media_cms
    ADD COLUMN IF NOT EXISTS showroom_view_x FLOAT NULL,
    ADD COLUMN IF NOT EXISTS showroom_view_y FLOAT NULL,
    ADD COLUMN IF NOT EXISTS showroom_view_z FLOAT NULL,
    ADD COLUMN IF NOT EXISTS showroom_fov DECIMAL(5,2) NULL;

ALTER TABLE showroom_hotspots
    ADD COLUMN IF NOT EXISTS arrow_rotation SMALLINT UNSIGNED NOT NULL DEFAULT 0;
