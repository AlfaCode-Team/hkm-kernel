<?php

declare(strict_types=1);

namespace Plugins\Edge\Domain;

/**
 * The resolved TLS choice for a render: the mode plus the certificate/key paths
 * used when the mode terminates TLS. A pure value object — defaults are resolved
 * from config in the Application layer, never here.
 */
final readonly class TlsConfig
{
    public function __construct(
        public TlsMode $mode,
        public string $cert,
        public string $key,
    ) {}

    /** A copy with the mode swapped (cert/key kept) — used to force TLS on the
     *  internal vhosts behind the SNI stream splitter. */
    public function withMode(TlsMode $mode): self
    {
        return new self($mode, $this->cert, $this->key);
    }
}
