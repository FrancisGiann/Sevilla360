#!/usr/bin/env python3
"""Render the original, loopable piano-style receptionist music WAV."""

from array import array
import math
from pathlib import Path
import struct
import wave


SAMPLE_RATE = 22_050
TEMPO_BPM = 80
BEATS_PER_BAR = 4
BAR_COUNT = 8
LOOP_SECONDS = BAR_COUNT * BEATS_PER_BAR * 60 / TEMPO_BPM
FRAME_COUNT = round(LOOP_SECONDS * SAMPLE_RATE)
NOTE_TAIL_SECONDS = 4.5
OUTPUT = Path(__file__).resolve().parents[1] / "assets/audio/receptionist-ambience.wav"


def midi_frequency(note: int) -> float:
    return 440 * (2 ** ((note - 69) / 12))


def note_decay(frequency: float) -> float:
    if frequency < 175:
        return 2.15
    if frequency < 260:
        return 1.9
    return 1.65


def piano_template(note: int) -> array:
    """Create a soft, slightly chorused piano-like struck tone."""
    frequency = midi_frequency(note)
    count = round(NOTE_TAIL_SECONDS * SAMPLE_RATE)
    attack_frames = round(0.012 * SAMPLE_RATE)
    harmonics = (1.0, 0.44, 0.21, 0.105, 0.052, 0.025)
    decay = note_decay(frequency)
    partial_envelopes = [1.0] * len(harmonics)
    partial_steps = [
        math.exp(-1 / (SAMPLE_RATE * decay / (1 + 0.34 * index)))
        for index in range(len(harmonics))
    ]
    phases = [0.0] * len(harmonics)
    phase_steps = [
        2 * math.pi * frequency * (index + 1) * math.sqrt(1 + 0.00008 * (index + 1) ** 2) / SAMPLE_RATE
        for index in range(len(harmonics))
    ]
    # A quiet, two-and-a-half-cent upper string adds the soft beating of a piano.
    paired_phase = 0.0
    paired_step = 2 * math.pi * frequency * 1.00145 / SAMPLE_RATE
    paired_decay = math.exp(-1 / (SAMPLE_RATE * decay))
    paired_envelope = 1.0
    samples = array("d")
    tail_start = count - round(0.18 * SAMPLE_RATE)

    for frame in range(count):
        attack = min(1.0, frame / attack_frames)
        value = 0.0
        for index, harmonic in enumerate(harmonics):
            value += harmonic * partial_envelopes[index] * math.sin(phases[index])
            phases[index] += phase_steps[index]
            partial_envelopes[index] *= partial_steps[index]
        value = attack * (0.88 * value + 0.12 * paired_envelope * math.sin(paired_phase))
        paired_phase += paired_step
        paired_envelope *= paired_decay

        # Fade only the very quiet end of the synthetic release to exact zero.
        if frame >= tail_start:
            tail_progress = (count - frame) / (count - tail_start)
            value *= tail_progress * tail_progress * (3 - 2 * tail_progress)
        samples.append(value)

    return samples


def score_events() -> list[tuple[float, int, float]]:
    """Return (beat, MIDI note, velocity) events in the 8-bar original score."""
    # Cmaj9, Em7, Am7, Fmaj9, Dm9, G13, Cmaj9/E, G13 resolves to the loop's Cmaj9.
    chords = (
        (48, 55, 59, 62, 64),
        (40, 55, 59, 62, 64),
        (45, 52, 55, 60, 64),
        (41, 53, 57, 60, 64, 67),
        (50, 57, 60, 64, 65),
        (43, 50, 53, 57, 64, 69),
        (52, 55, 59, 62, 64),
        (43, 50, 53, 57, 64, 69),
    )
    events: list[tuple[float, int, float]] = []

    for bar, chord in enumerate(chords):
        bar_start = bar * BEATS_PER_BAR
        for index, note in enumerate(chord):
            # Root and bass are softer; the close upper voicing stays warm and light.
            velocity = 0.14 if index == 0 else 0.105 if index == 1 else 0.09
            events.append((bar_start + (0.035 if index == 0 else 0), note, velocity))

        # A single quiet upper chord tone answers each chord without a strong pulse.
        answer_note = chord[-2]
        events.append((bar_start + 2.45, answer_note + 12, 0.052))

    melody = (
        (0.55, 76, 0.105), (2.25, 74, 0.09), (3.35, 71, 0.09),
        (4.85, 67, 0.08), (6.6, 71, 0.085),
        (8.5, 72, 0.095), (10.15, 76, 0.09), (11.25, 67, 0.08),
        (13.1, 69, 0.09), (14.7, 67, 0.08),
        (16.55, 77, 0.095), (18.25, 76, 0.085), (19.3, 74, 0.08),
        (20.8, 71, 0.085), (22.3, 69, 0.08),
        (23.25, 67, 0.075),
    )
    events.extend(melody)
    return events


def render() -> None:
    beat_seconds = 60 / TEMPO_BPM
    loop = array("d", [0.0]) * FRAME_COUNT
    templates: dict[int, array] = {}
    events = score_events()

    # Include the previous cycle so decaying notes crossing the boundary are periodic.
    for cycle in (-1, 0):
        for beat, note, velocity in events:
            start_frame = round((cycle * LOOP_SECONDS + beat * beat_seconds) * SAMPLE_RATE)
            if note not in templates:
                templates[note] = piano_template(note)
            template = templates[note]
            template_start = max(0, -start_frame)
            loop_start = max(0, start_frame)
            sample_count = min(len(template) - template_start, FRAME_COUNT - loop_start)
            for offset in range(sample_count):
                loop[loop_start + offset] += template[template_start + offset] * velocity

    peak = max(abs(sample) for sample in loop)
    if peak == 0:
        raise RuntimeError("Rendered loop is silent")

    # Leave headroom; the player fades the optional music to 0.14 volume.
    gain = 0.78 / peak
    pcm = bytearray(FRAME_COUNT * 2)
    for index, sample in enumerate(loop):
        value = max(-1.0, min(1.0, sample * gain))
        struct.pack_into("<h", pcm, index * 2, round(value * 32_767))

    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    with wave.open(str(OUTPUT), "wb") as output:
        output.setnchannels(1)
        output.setsampwidth(2)
        output.setframerate(SAMPLE_RATE)
        output.writeframes(pcm)

    print(f"Wrote {OUTPUT} ({LOOP_SECONDS:.2f}s, {len(pcm) + 44:,} bytes, peak {0.78:.2f})")


if __name__ == "__main__":
    render()
