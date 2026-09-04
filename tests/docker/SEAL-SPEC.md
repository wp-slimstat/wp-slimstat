# ENTROPY_V1

Draw 32 bytes from \`/dev/urandom\` and encode them as 64 lowercase hexadecimal characters.
Publish \`SHA-256(entropy_hex)\` before capture. The low bit of the first decoded byte selects
the assignment: zero maps arm-1 to the first supplied ref; one maps arm-1 to the second.

The mapping is mode 0600 inside a mode-0700 directory. Unsealing publishes the entropy only
after every adjudication precondition passes. Recompute the commitment and assignment from the
reveal; neither is accepted on trust.
