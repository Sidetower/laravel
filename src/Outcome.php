<?php

declare(strict_types=1);

namespace Sidetower\Laravel;

/**
 * What became of one request to the server, and so of the items in it.
 */
enum Outcome: string
{
    /** 202: the server took the items (some may be listed as rejected; they will be refused again, so they go too). */
    case Accepted = 'accepted';

    /** 400, 401, 403 and other 4xx: sending the same bytes again cannot work. The items go. */
    case Refused = 'refused';

    /** 413: the body was too big. Halve it. */
    case TooLarge = 'too_large';

    /** 429, 5xx, a timeout, no connection, or an answer that is not the server's: keep the items and stop for now. */
    case Later = 'later';
}
