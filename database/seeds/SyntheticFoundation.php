<?php
declare(strict_types=1); // keep typed values strict

use Phinx\Seed\AbstractSeed; // Phinx supplies the seed runner and transaction

final class SyntheticFoundation extends AbstractSeed
{
    public function run(): void {
        $pdo = $this->getAdapter()->getConnection(); // use the connection Phinx has already opened
        // Phinx owns the transaction around this seed's run() method.
        // add the development role if missing; leave an existing role unchanged
        $statement = $pdo->prepare('INSERT INTO roles (code, label) VALUES (:code, :label) ON DUPLICATE KEY UPDATE code = code');
        $statement->execute(['code' => 'rdpo_admin', 'label' => 'RDPO / Administrator (development label)']);
        // this fixture key lets repeated runs find the same synthetic thesis
        $lookup = $pdo->prepare('SELECT id FROM theses WHERE fixture_key = :fixture_key');
        $lookup->execute(['fixture_key' => 'synthetic_multi_author']);
        $existingThesis = $lookup->fetch();
        if (!$existingThesis) { // do not overwrite existing fixture data
            $statement = $pdo->prepare(
                'INSERT INTO theses (fixture_key, title, publication_year, program, abstract, keywords)
                 VALUES (:fixture_key, :title, :year, :program, :abstract, :keywords)'
            );
            $statement->execute([
                'fixture_key' => 'synthetic_multi_author',
                'title' => 'BSIT Sample Thesis: Reading Room Catalog', 'year' => 2026,
                'program' => 'BS Information Technology',
                'abstract' => 'Sample abstract for testing. This record checks that one thesis can have several authors.',
                'keywords' => 'catalog, reading room, sample',
            ]);
            $thesisId = (int) $pdo->lastInsertId(); // ID of the thesis inserted immediately above
            $statement = $pdo->prepare('INSERT INTO thesis_authors (thesis_id, author_name, author_order) VALUES (:thesis_id, :name, :position)');
            // each author has their own row, joined to the thesis through thesis_id
            $authorNames = ['Maria Clara Santos', 'Jose Miguel Reyes']; // made-up sample names
            foreach ($authorNames as $index => $name) {
                $position = $index + 1; // store author positions as 1, 2, ... rather than array indexes 0, 1, ...
                $statement->execute(['thesis_id' => $thesisId, 'name' => $name, 'position' => $position]);
            }
        }
    }
}
