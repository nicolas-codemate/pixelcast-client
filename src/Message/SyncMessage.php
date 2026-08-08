<?php

declare(strict_types=1);

namespace App\Message;

interface SyncMessage
{
    /**
     * The same sync as dispatched by `app:sync`: the diagnostic command must push the whole
     * group whatever the hours it declares, so that testing it in the evening stays possible.
     */
    public function dispatchedByHand(): static;
}
