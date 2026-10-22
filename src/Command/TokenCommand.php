<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Velundo\ContaoConnect\Service\TokenService;

/**
 * vendor/bin/contao-console velundo:token create "<Bezeichnung>" [--allow-publish] [--allow-live-edit] | list | revoke <Kennung>
 */
final class TokenCommand extends Command
{
    public function __construct(private readonly TokenService $tokens)
    {
        parent::__construct('velundo:token');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Zugangstoken für die velundo-App erzeugen, auflisten oder widerrufen')
            ->setHelp(
                "Beispiele:\n" .
                "  velundo:token create \"Agentur Test\"                     Token erzeugen (wird nur einmal angezeigt)\n" .
                "  velundo:token create \"Agentur Test\" --allow-publish     … darf zusätzlich veröffentlichen\n" .
                "  velundo:token list                                       Tokens mit Rechten auflisten\n" .
                "  velundo:token revoke 3                                   Token widerrufen\n\n" .
                "Ohne Zusatzrecht ändert ein Token nur unveröffentlichte Inhalte und Metadaten (SEO-Felder, Datum).\n" .
                "  --allow-publish     Seiten, Inhaltselemente und Nachrichten sichtbar schalten\n" .
                "  --allow-live-edit   Titel, Überschriften und Texte bereits sichtbarer Datensätze ändern\n\n" .
                'Gespeichert wird nur der SHA-256-Hash in der Tabelle tl_velundo_token (anlegen bzw. aktualisieren mit contao:migrate).'
            )
            ->addArgument('action', InputArgument::REQUIRED, 'create, list oder revoke')
            ->addArgument('value', InputArgument::OPTIONAL, 'Bezeichnung des Tokens (create) oder Kennung (revoke)')
            ->addOption('allow-publish', null, InputOption::VALUE_NONE, 'Zusatzrecht: veröffentlichen bzw. sichtbar schalten')
            ->addOption('allow-live-edit', null, InputOption::VALUE_NONE, 'Zusatzrecht: Titel und Texte sichtbarer Datensätze ändern');
    }

    /** @return list<string> */
    private static function scopesFromInput(InputInterface $input): array
    {
        $scopes = [];
        foreach (TokenService::SCOPES as $scope) {
            if ($input->getOption('allow-' . str_replace('_', '-', $scope)) === true) {
                $scopes[] = $scope;
            }
        }
        return $scopes;
    }

    /** @param list<string> $scopes */
    private static function scopesText(array $scopes): string
    {
        return $scopes === [] ? 'nur Entwürfe und Metadaten' : implode(', ', $scopes);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = strtolower((string)$input->getArgument('action'));
        $value = trim((string)$input->getArgument('value'));

        switch ($action) {
            case 'create':
                if ($value === '') {
                    $io->error('Bitte eine Bezeichnung angeben: velundo:token create "Agentur Test"');
                    return Command::INVALID;
                }
                try {
                    $created = $this->tokens->create($value, self::scopesFromInput($input));
                } catch (\InvalidArgumentException $e) {
                    $io->error($e->getMessage());
                    return Command::INVALID;
                } catch (\RuntimeException $e) {
                    $io->error($e->getMessage());
                    return Command::FAILURE;
                }
                $io->success(sprintf('Token „%s“ (Kennung %d, Rechte: %s) wurde erzeugt. Es wird nur dieses eine Mal angezeigt:', $created['name'], $created['id'], self::scopesText($created['scopes'])));
                $output->writeln($created['token']);
                $output->writeln('');
                $io->text('In der velundo-App als Token der Verbindung eintragen, zusammen mit der Adresse der Website (https://…).');
                return Command::SUCCESS;

            case 'list':
                $rows = [];
                foreach ($this->tokens->list() as $token) {
                    $rows[] = [
                        $token['id'],
                        $token['name'],
                        date('Y-m-d H:i', $token['created']),
                        $token['last_used'] === null ? '–' : date('Y-m-d H:i', $token['last_used']),
                        self::scopesText($token['scopes']),
                        $token['revoked'] === null ? 'aktiv' : 'widerrufen ' . date('Y-m-d H:i', $token['revoked']),
                    ];
                }
                if ($rows === []) {
                    $io->note('Noch kein Token vorhanden. Erzeugen mit: velundo:token create "Bezeichnung"');
                    return Command::SUCCESS;
                }
                (new Table($output))->setHeaders(['Kennung', 'Bezeichnung', 'Erzeugt', 'Zuletzt verwendet', 'Rechte', 'Status'])->setRows($rows)->render();
                return Command::SUCCESS;

            case 'revoke':
                if (!ctype_digit($value) || (int)$value <= 0) {
                    $io->error('Bitte die Kennung angeben (Zahl, siehe velundo:token list).');
                    return Command::INVALID;
                }
                if (!$this->tokens->revoke((int)$value)) {
                    $io->error(sprintf('Kein aktives Token mit der Kennung %s.', $value));
                    return Command::FAILURE;
                }
                $io->success(sprintf('Token %s wurde widerrufen.', $value));
                return Command::SUCCESS;

            default:
                $io->error('Unbekannte Aktion. Erlaubt sind create, list und revoke.');
                return Command::INVALID;
        }
    }
}
