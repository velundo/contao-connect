<?php

declare(strict_types=1);

/*
 * Zähler der Ratenbegrenzung je Token und Minutenfenster (eindeutig, atomar erhöht). Alte Fenster entfernt das
 * Bundle selbst. Angelegt mit „contao:migrate“; keine Bearbeitung im Backend.
 */

use Contao\DC_Table;

$GLOBALS['TL_DCA']['tl_velundo_rate'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'closed' => true,
        'notEditable' => true,
        'notCopyable' => true,
        'notDeletable' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'token_id,window_start' => 'unique',
            ],
        ],
    ],
    'fields' => [
        'id' => ['sql' => 'int(10) unsigned NOT NULL auto_increment'],
        'token_id' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
        'window_start' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
        'hits' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
    ],
];
