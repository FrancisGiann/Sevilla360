# Receptionist background music

`receptionist-ambience.wav` is an original, locally synthesized 24-second
piano-style lounge loop for the optional receptionist music control. It uses
soft struck harmonic tones, a slow eight-bar chord progression, and a sparse
original melody. It has no vocals, percussion, nature sounds, samples, or
external recordings; the piano timbre is synthetic and is not a recording of
an acoustic piano.

The WAV is mono 22.05 kHz, 16-bit PCM and is about 1 MB. Its note tails are
rendered across the loop boundary so it repeats without a fade or silence.
The browser loads it only after the user turns music on, with `preload="none"`,
and fades playback to a quiet 0.14 volume target.

Regenerate it with the Python standard library:

```sh
python3 scripts/generate_receptionist_music.py
```
