# SlimStat dependency snapshot

This build input reconstructs the complete dependency payload committed in
`src/Dependencies` at Free commit `3055864f0e7ec979d8896f5d9d9eae28020ef8e9`.
It includes Browscap, Flysystem, their transitive dependencies, polyfill stubs,
bootstrap files, resources and licenses. It is not a pristine upstream package.

The reconstruction copies the baseline tree byte-for-byte, then removes the
existing `SlimStat\Dependencies\` prefix from namespace references so locked WP
Scoper can regenerate that same private namespace. `MaxMind\Exception` stays
unscoped to preserve the baseline declarations; the two bundled MaxMind library
roots are mapped separately.
