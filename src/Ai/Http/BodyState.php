<?php

declare(strict_types=1);

namespace Sidetower\Laravel\Ai\Http;

/**
 * What the SDK could learn from the body of a response without disturbing the application.
 */
enum BodyState
{
    /** Not seekable (a live stream), of unknown or large size, or otherwise left alone. Nothing was read. */
    case Unreadable;

    /** Read, or judged by its content type, and not the JSON or event stream a provider answers with. */
    case Invalid;

    /** One JSON document: an object, or a list of objects (Gemini's non-SSE stream). */
    case Json;

    /** A buffered server-sent event stream; only the events that mention usage are kept. */
    case Stream;
}
