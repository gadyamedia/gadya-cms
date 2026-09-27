<?php

namespace Gadya\Cms\Portal;

use Gadya\Cms\Portal\Commands\ComingSoonOff;
use Gadya\Cms\Portal\Commands\ComingSoonOn;
use Gadya\Cms\Portal\Commands\RequestContentChange;
use Gadya\Cms\Portal\Commands\RunBackup;

/**
 * What the Gadya Media portal may ask this site to do, on top of what
 * gadya/connect does itself.
 *
 * gadya/connect verifies each command - signed by the portal, not
 * expired, on its allow-list - and then runs whichever class tagged
 * `gadya-connect.remote-commands` answers to its type. Each class here
 * has the two methods it looks for, `type()` and `handle(array)`, and no
 * compile-time tie to connect, so this package works with any connect
 * release and simply goes unused by one that runs no commands.
 */
final class RemoteCommands
{
    public const TAG = 'gadya-connect.remote-commands';

    /** @var list<class-string> */
    public const HANDLERS = [
        ComingSoonOn::class,
        ComingSoonOff::class,
        RunBackup::class,
        RequestContentChange::class,
    ];
}
