<?php

namespace Gitonomy\Git\Event;

final class Events
{
    /**
     * @see PreCommandEvent
     */
    const PRE_COMMAND  = 'pre_command';

    /**
     * @see PostCommandEvent
     */
    const POST_COMMAND = 'post_command';
}
