<?php
declare(strict_types=1);

/**
 * Build the room numbers for a bulk hotel-room creation request.
 *
 * Letter-only identifiers use spreadsheet-style sequencing (Z, AA, AB) and
 * retain the case of each character in the supplied starting identifier.
 * Numeric identifiers retain their original zero padding and optional prefix.
 *
 * @return list<string>
 */
function hotel_bulk_room_numbers(string $start, int $quantity): array
{
    if ($quantity < 1 || $quantity > 100) {
        throw new InvalidArgumentException('Bulk quantity must be between 1 and 100.');
    }

    if ($start === '' || strlen($start) > 20) {
        throw new InvalidArgumentException('Starting room number must be 20 characters or fewer.');
    }

    if (preg_match('/\A[A-Za-z]+\z/D', $start)) {
        $roomNumbers = [];
        $current = $start;
        for ($offset = 0; $offset < $quantity; $offset++) {
            if (strlen($current) > 20) {
                throw new InvalidArgumentException('Every generated room number must be 20 characters or fewer.');
            }
            $roomNumbers[] = $current;
            if ($offset + 1 < $quantity) {
                $current = hotel_increment_room_letters($current);
            }
        }
        return $roomNumbers;
    }

    if (!preg_match('/\A(?:(?<prefix>[A-Za-z]+)-)?(?<number>\d{1,6})\z/D', $start, $matches)) {
        throw new InvalidArgumentException('Starting room number must use letters (e.g. A), digits (e.g. 101), or a letter prefix and digits (e.g. A-101).');
    }

    $prefix = isset($matches['prefix']) && $matches['prefix'] !== '' ? $matches['prefix'] . '-' : '';
    $numberText = $matches['number'];
    $number = (int)$numberText;
    if ($number + $quantity - 1 > 999999) {
        throw new InvalidArgumentException('Bulk room range cannot exceed 999999.');
    }

    $roomNumbers = [];
    for ($offset = 0; $offset < $quantity; $offset++) {
        $roomNumber = $prefix . str_pad((string)($number + $offset), strlen($numberText), '0', STR_PAD_LEFT);
        if (strlen($roomNumber) > 20) {
            throw new InvalidArgumentException('Every generated room number must be 20 characters or fewer.');
        }
        $roomNumbers[] = $roomNumber;
    }

    return $roomNumbers;
}

function hotel_increment_room_letters(string $letters): string
{
    $characters = str_split($letters);
    for ($index = count($characters) - 1; $index >= 0; $index--) {
        $character = $characters[$index];
        $uppercase = $character >= 'A' && $character <= 'Z';
        $lastLetter = $uppercase ? 'Z' : 'z';
        if ($character !== $lastLetter) {
            $characters[$index] = chr(ord($character) + 1);
            return implode('', $characters);
        }
        $characters[$index] = $uppercase ? 'A' : 'a';
    }

    $firstCharacter = $letters[0];
    array_unshift($characters, $firstCharacter >= 'A' && $firstCharacter <= 'Z' ? 'A' : 'a');
    return implode('', $characters);
}
