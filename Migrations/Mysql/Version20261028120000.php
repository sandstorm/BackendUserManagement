<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Package rename Sandstorm.NeosTwoFactorAuthentication -> Sandstorm.BackendUserManagement: the entity moved to the
 * SecondFactor namespace and got an explicit table name. Rename the table in place, keeping all second factors.
 * Index and foreign key are renamed to the names Doctrine derives for the new table, so the schema stays in sync.
 */
final class Version20261028120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename the second factor table after the package rename to Sandstorm.BackendUserManagement';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySqlPlatform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySqlPlatform,'."
        );

        $this->addSql('RENAME TABLE sandstorm_neostwofactorauthentication_domain_model_secondfactor TO sandstorm_backendusermanagement_secondfactor');
        $this->addSql('ALTER TABLE sandstorm_backendusermanagement_secondfactor DROP FOREIGN KEY FK_29EF8A7F7D3656A4');
        $this->addSql('ALTER TABLE sandstorm_backendusermanagement_secondfactor RENAME INDEX IDX_29EF8A7F7D3656A4 TO IDX_E45DE6B7D3656A4');
        $this->addSql('ALTER TABLE sandstorm_backendusermanagement_secondfactor ADD CONSTRAINT FK_E45DE6B7D3656A4 FOREIGN KEY (account) REFERENCES neos_flow_security_account (persistence_object_identifier) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySqlPlatform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySqlPlatform,'."
        );

        $this->addSql('ALTER TABLE sandstorm_backendusermanagement_secondfactor DROP FOREIGN KEY FK_E45DE6B7D3656A4');
        $this->addSql('ALTER TABLE sandstorm_backendusermanagement_secondfactor RENAME INDEX IDX_E45DE6B7D3656A4 TO IDX_29EF8A7F7D3656A4');
        $this->addSql('ALTER TABLE sandstorm_backendusermanagement_secondfactor ADD CONSTRAINT FK_29EF8A7F7D3656A4 FOREIGN KEY (account) REFERENCES neos_flow_security_account (persistence_object_identifier) ON DELETE CASCADE');
        $this->addSql('RENAME TABLE sandstorm_backendusermanagement_secondfactor TO sandstorm_neostwofactorauthentication_domain_model_secondfactor');
    }
}
