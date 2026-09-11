# Receptionist ambience

`receptionist-ambience.wav` is an original locally generated neutral resort
ambience for the optional receptionist sound toggle. It is a short, filtered
brown-noise bed generated with FFmpeg's `anoisesrc` filter (70–1200 Hz), with
no music, speech, samples, or external recording. The source is normalized to
useful headroom while the UI caps playback at 0.3. It is bundled under the
project's existing asset terms and is not loaded until the visitor opts in.
