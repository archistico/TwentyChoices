<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260718000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M1.1 choice-pair catalog, protected final door, seed data and immutable round snapshots.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->abortIf(true, 'This prototype migration targets SQLite only.');
        }

        $this->addSql("ALTER TABLE choice_pair ADD COLUMN pair_type VARCHAR(20) NOT NULL DEFAULT 'REGULAR'");
        $this->addSql('ALTER TABLE choice_pair ADD COLUMN is_system BOOLEAN NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE choice_pair ADD COLUMN sort_order INTEGER NOT NULL DEFAULT 0');
        $this->addSql("ALTER TABLE round_question ADD COLUMN choice_pair_code_snapshot VARCHAR(60) NOT NULL DEFAULT ''");
        $this->addSql("ALTER TABLE round_question ADD COLUMN category_snapshot VARCHAR(60) NOT NULL DEFAULT ''");
        $this->addSql("ALTER TABLE round_question ADD COLUMN pair_type_snapshot VARCHAR(20) NOT NULL DEFAULT 'REGULAR'");

        $this->addSql("CREATE UNIQUE INDEX uniq_choice_pair_final_door ON choice_pair (pair_type) WHERE pair_type = 'FINAL_DOOR'");
        $this->addSql('CREATE INDEX idx_choice_pair_catalog ON choice_pair (pair_type, is_active, sort_order)');

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_choice_pair_validate_insert
BEFORE INSERT ON choice_pair
WHEN NEW.pair_type NOT IN ('REGULAR', 'FINAL_DOOR')
  OR NEW.sort_order < 0
  OR (NEW.pair_type = 'FINAL_DOOR' AND (NEW.is_active <> 1 OR NEW.is_system <> 1))
BEGIN
    SELECT RAISE(ABORT, 'Invalid choice-pair catalog state');
END
SQL);
        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_choice_pair_validate_update
BEFORE UPDATE ON choice_pair
WHEN NEW.pair_type NOT IN ('REGULAR', 'FINAL_DOOR')
  OR NEW.sort_order < 0
  OR (OLD.pair_type = 'FINAL_DOOR' AND (NEW.pair_type <> 'FINAL_DOOR' OR NEW.is_active <> 1 OR NEW.is_system <> 1))
BEGIN
    SELECT RAISE(ABORT, 'The mandatory final door is protected');
END
SQL);
        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_choice_pair_protect_final_delete
BEFORE DELETE ON choice_pair
WHEN OLD.pair_type = 'FINAL_DOOR'
BEGIN
    SELECT RAISE(ABORT, 'The mandatory final door cannot be deleted');
END
SQL);
        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_round_question_validate_insert
BEFORE INSERT ON round_question
WHEN (NEW.step_number = 20 AND NEW.pair_type_snapshot <> 'FINAL_DOOR')
  OR (NEW.step_number < 20 AND NEW.pair_type_snapshot <> 'REGULAR')
BEGIN
    SELECT RAISE(ABORT, 'Invalid round-question position');
END
SQL);
        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_round_question_immutable
BEFORE UPDATE ON round_question
BEGIN
    SELECT RAISE(ABORT, 'Round-question snapshots are immutable');
END
SQL);

        $this->addSql(<<<'SQL'
INSERT INTO choice_pair (
     id
    ,code
    ,option_a_text
    ,option_b_text
    ,category
    ,is_active
    ,created_at
    ,updated_at
    ,pair_type
    ,is_system
    ,sort_order
)
VALUES
('00000000000000000000000001', 'mare-montagna', 'Mare', 'Montagna', 'Natura', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 10),
('00000000000000000000000002', 'caldo-freddo', 'Caldo', 'Freddo', 'Sensazioni', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 20),
('00000000000000000000000003', 'alba-tramonto', 'Alba', 'Tramonto', 'Tempo', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 30),
('00000000000000000000000004', 'estate-inverno', 'Estate', 'Inverno', 'Stagioni', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 40),
('00000000000000000000000005', 'sole-pioggia', 'Sole', 'Pioggia', 'Meteo', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 50),
('00000000000000000000000006', 'giorno-notte', 'Giorno', 'Notte', 'Tempo', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 60),
('00000000000000000000000007', 'citta-campagna', 'Città', 'Campagna', 'Luoghi', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 70),
('00000000000000000000000008', 'bosco-deserto', 'Bosco', 'Deserto', 'Natura', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 80),
('00000000000000000000000009', 'lago-fiume', 'Lago', 'Fiume', 'Natura', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 90),
('0000000000000000000000000A', 'neve-sabbia', 'Neve', 'Sabbia', 'Natura', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 100),
('0000000000000000000000000B', 'caffe-cappuccino', 'Caffè', 'Cappuccino', 'Gusto', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 110),
('0000000000000000000000000C', 'dolce-salato', 'Dolce', 'Salato', 'Gusto', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 120),
('0000000000000000000000000D', 'pizza-pasta', 'Pizza', 'Pasta', 'Cibo', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 130),
('0000000000000000000000000E', 'vino-birra', 'Vino', 'Birra', 'Bevande', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 140),
('0000000000000000000000000F', 'cioccolato-vaniglia', 'Cioccolato', 'Vaniglia', 'Gusto', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 150),
('0000000000000000000000000G', 'te-caffe', 'Tè', 'Caffè', 'Bevande', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 160),
('0000000000000000000000000H', 'pane-riso', 'Pane', 'Riso', 'Cibo', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 170),
('0000000000000000000000000J', 'colazione-cena', 'Colazione', 'Cena', 'Cibo', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 180),
('0000000000000000000000000K', 'libro-film', 'Libro', 'Film', 'Cultura', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 190),
('0000000000000000000000000M', 'musica-podcast', 'Musica', 'Podcast', 'Media', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 200),
('0000000000000000000000000N', 'teatro-cinema', 'Teatro', 'Cinema', 'Cultura', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 210),
('0000000000000000000000000P', 'fotografia-disegno', 'Fotografia', 'Disegno', 'Creatività', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 220),
('0000000000000000000000000Q', 'carta-digitale', 'Carta', 'Digitale', 'Stile', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 230),
('0000000000000000000000000R', 'commedia-thriller', 'Commedia', 'Thriller', 'Cultura', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 240),
('0000000000000000000000000S', 'classico-moderno', 'Classico', 'Moderno', 'Stile', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 250),
('0000000000000000000000000T', 'cane-gatto', 'Cane', 'Gatto', 'Animali', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 260),
('0000000000000000000000000V', 'aquila-lupo', 'Aquila', 'Lupo', 'Animali', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 270),
('0000000000000000000000000W', 'delfino-cavallo', 'Delfino', 'Cavallo', 'Animali', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 280),
('0000000000000000000000000X', 'cuori-quadri', 'Cuori', 'Quadri', 'Simboli', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 290),
('0000000000000000000000000Y', 'oro-argento', 'Oro', 'Argento', 'Materiali', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 300),
('0000000000000000000000000Z', 'moto-macchina', 'Moto', 'Macchina', 'Viaggio', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 310),
('00000000000000000000000010', 'treno-aereo', 'Treno', 'Aereo', 'Viaggio', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 320),
('00000000000000000000000011', 'bicicletta-passeggiata', 'Bicicletta', 'Passeggiata', 'Movimento', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 330),
('00000000000000000000000012', 'veloce-lento', 'Veloce', 'Lento', 'Ritmo', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 340),
('00000000000000000000000013', 'ordine-caos', 'Ordine', 'Caos', 'Carattere', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 350),
('00000000000000000000000014', 'istinto-ragione', 'Istinto', 'Ragione', 'Carattere', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 360),
('00000000000000000000000015', 'passato-futuro', 'Passato', 'Futuro', 'Tempo', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 370),
('00000000000000000000000016', 'avventura-relax', 'Avventura', 'Relax', 'Esperienze', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 380),
('00000000000000000000000017', 'sorpresa-certezza', 'Sorpresa', 'Certezza', 'Carattere', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 390),
('00000000000000000000000018', 'silenzio-rumore', 'Silenzio', 'Rumore', 'Sensazioni', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 400),
('00000000000000000000000019', 'compagnia-solitudine', 'Compagnia', 'Solitudine', 'Esperienze', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 410),
('0000000000000000000000001A', 'salita-discesa', 'Salita', 'Discesa', 'Movimento', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 420),
('0000000000000000000000001B', 'aperto-chiuso', 'Aperto', 'Chiuso', 'Spazio', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 430),
('0000000000000000000000001C', 'chiaro-scuro', 'Chiaro', 'Scuro', 'Percezione', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'REGULAR', 0, 440),
('0000000000000000000000001D', 'porta-finale', 'Porta rossa', 'Porta blu', 'Finale', 1, '2026-07-18 00:00:00.000000', '2026-07-18 00:00:00.000000', 'FINAL_DOOR', 1, 10000)
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER trg_round_question_immutable');
        $this->addSql('DROP TRIGGER trg_round_question_validate_insert');
        $this->addSql('DROP TRIGGER trg_choice_pair_protect_final_delete');
        $this->addSql('DROP TRIGGER trg_choice_pair_validate_update');
        $this->addSql('DROP TRIGGER trg_choice_pair_validate_insert');
        $this->addSql('DROP INDEX idx_choice_pair_catalog');
        $this->addSql('DROP INDEX uniq_choice_pair_final_door');
        $this->addSql("DELETE FROM choice_pair WHERE created_at = '2026-07-18 00:00:00.000000'");
        $this->addSql('ALTER TABLE round_question DROP COLUMN pair_type_snapshot');
        $this->addSql('ALTER TABLE round_question DROP COLUMN category_snapshot');
        $this->addSql('ALTER TABLE round_question DROP COLUMN choice_pair_code_snapshot');
        $this->addSql('ALTER TABLE choice_pair DROP COLUMN sort_order');
        $this->addSql('ALTER TABLE choice_pair DROP COLUMN is_system');
        $this->addSql('ALTER TABLE choice_pair DROP COLUMN pair_type');
    }
}
