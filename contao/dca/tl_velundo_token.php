<?php

declare(strict_types=1);

/*
 * Zugangstokens der velundo-Schnittstelle: nur der SHA-256-Hash, Bezeichnung und Zeitpunkte. Die Tabelle legt
 * „contao:migrate“ an; eine Bearbeitung im Backend ist nicht vorgesehen (Pflege nur über velundo:token).
 */

use Contao\DC_Table;

$GLOBALS['TL_DCA']['tl_velundo_token'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'closed' => true,
        'notEditable' => true,
        'notCopyable' => true,
        'notDeletable' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'token_hash' => 'unique',
            ],
        ],
    ],
    'fields' => [
        'id' => ['sql' => 'int(10) unsigned NOT NULL auto_increment'],
        'tstamp' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
        'name' => ['sql' => "varchar(100) NOT NULL default ''"],
        'token_hash' => ['sql' => "char(64) NOT NULL default ''"],
        'created' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
        'last_used' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
        'revoked' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
    ],
];
