<?php

declare(strict_types=1);

/*
 * Zugangstokens der velundo-Schnittstelle: nur der SHA-256-Hash, Bezeichnung und Zeitpunkte. Die Tabelle legt
 * „contao:migrate“ an; eine Bearbeitung im Backend ist nicht vorgesehen (Pflege nur über velundo:token).
 * Spalten in der Array-Schreibweise von Doctrine (Contao 5.3 und 6).
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
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'name' => ['sql' => ['type' => 'string', 'length' => 100, 'default' => '']],
        'token_hash' => ['sql' => ['type' => 'string', 'length' => 64, 'fixed' => true, 'default' => '']],
        'created' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'last_used' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'revoked' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
    ],
];
