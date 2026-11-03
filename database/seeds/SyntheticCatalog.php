<?php
declare(strict_types=1); // keep typed values strict

use Phinx\Seed\AbstractSeed; // Phinx supplies the seed runner and transaction

// sample thesis records so the search and filter pages have something to show during local development
// the titles are kept simple ("BSIT Sample Thesis: ..."), and every name is made up for testing;
// none of these records, people or abstracts come from a real WCC manuscript
final class SyntheticCatalog extends AbstractSeed
{
    public function run(): void {
        $pdo = $this->getAdapter()->getConnection(); // use the connection Phinx has already opened

        // one entry per sample thesis; each has its own topic so searching for a word finds a different record
        // the last one is left unpublished to show that hidden records stay off the public page
        $records = [
            ['key' => 'synthetic_catalog_01', 'title' => 'BSIT Sample Thesis: Flood Level Monitoring', 'year' => 2024, 'program' => 'BS Information Technology',
                'adviser' => 'Prof. Ramon Aguilar', 'keywords' => 'IoT, flood monitoring, sensors, SMS alerts', 'public' => 1, 'pages' => 112,
                'authors' => ['Angelica Dela Cruz', 'Mark Anthony Villanueva'],
                'abstract' => 'Sample abstract for testing. Water level sensors send flood readings to a web dashboard and text alerts to residents.'],
            ['key' => 'synthetic_catalog_02', 'title' => 'BSIT Sample Thesis: Library Inventory System', 'year' => 2023, 'program' => 'BS Information Technology',
                'adviser' => 'Prof. Ramon Aguilar', 'keywords' => 'inventory, library, web application, barcode', 'public' => 1, 'pages' => 98,
                'authors' => ['Kristine Mae Bautista', 'John Paul Garcia', 'Rhea Mendoza'],
                'abstract' => 'Sample abstract for testing. A barcode-based inventory replaces handwritten book logs and borrowing records.'],
            ['key' => 'synthetic_catalog_03', 'title' => 'BSCS Sample Thesis: Nurse Shift Scheduling', 'year' => 2022, 'program' => 'BS Computer Science',
                'adviser' => 'Dr. Liza Manalo', 'keywords' => 'genetic algorithm, scheduling, optimization', 'public' => 1, 'pages' => 87,
                'authors' => ['Carlo Ramos'],
                'abstract' => 'Sample abstract for testing. A genetic algorithm builds weekly nurse duty schedules that respect rest days and staffing limits.'],
            ['key' => 'synthetic_catalog_04', 'title' => 'BSCS Sample Thesis: Canteen Review Sentiment Analysis', 'year' => 2025, 'program' => 'BS Computer Science',
                'adviser' => 'Dr. Liza Manalo', 'keywords' => 'sentiment analysis, natural language processing, reviews', 'public' => 1, 'pages' => 76,
                'authors' => ['Patricia Lim', 'Joshua Aquino'],
                'abstract' => 'Sample abstract for testing. Short food reviews are sorted into positive, negative or neutral and common complaints are summarized.'],
            ['key' => 'synthetic_catalog_05', 'title' => 'BSIT Sample Thesis: QR Code Attendance', 'year' => 2021, 'program' => 'BS Information Technology',
                'adviser' => 'Prof. Ramon Aguilar', 'keywords' => 'QR code, attendance, mobile application', 'public' => 1, 'pages' => 64,
                'authors' => ['Bea Castillo', 'Renz Navarro'],
                'abstract' => 'Sample abstract for testing. Students scan a QR code at the door so attendance is recorded without paper sign-in sheets.'],
            ['key' => 'synthetic_catalog_06', 'title' => 'BSCS Sample Thesis: Rice Leaf Disease Detection', 'year' => 2026, 'program' => 'BS Computer Science',
                'adviser' => 'Dr. Liza Manalo', 'keywords' => 'image classification, machine learning, agriculture', 'public' => 1, 'pages' => 120,
                'authors' => ['Gabriel Torres', 'Nicole Fernandez'],
                'abstract' => 'Sample abstract for testing. An image classifier recognizes common rice leaf diseases from phone photos.'],
            ['key' => 'synthetic_catalog_07', 'title' => 'BS Psychology Sample Thesis: Stress Among Working Students', 'year' => 2022, 'program' => 'BS Psychology',
                'adviser' => 'Dr. Teresa Valdez', 'keywords' => 'stress, working students, online learning, survey', 'public' => 1, 'pages' => 91,
                'authors' => ['Jasmine Cruz', 'Kevin Salazar', 'Trisha Gonzales'],
                'abstract' => 'Sample abstract for testing. A survey compares reported stress levels of working and non-working students during online classes.'],
            ['key' => 'synthetic_catalog_08', 'title' => 'BSBA Sample Thesis: Point of Sale for Small Stores', 'year' => 2020, 'program' => 'BS Business Administration',
                'adviser' => 'Prof. Edwin Soriano', 'keywords' => 'point of sale, small business, sales records', 'public' => 1, 'pages' => 70,
                'authors' => ['Rafael Domingo'],
                'abstract' => 'Sample abstract for testing. A simple tablet point-of-sale system is compared with notebook-based daily sales records.'],
            ['key' => 'synthetic_catalog_09', 'title' => 'BSN Sample Thesis: Dengue Awareness App', 'year' => 2023, 'program' => 'BS Nursing',
                'adviser' => 'Dr. Teresa Valdez', 'keywords' => 'dengue, health awareness, mobile application', 'public' => 1, 'pages' => 83,
                'authors' => ['Camille Flores', 'Adrian Pascual'],
                'abstract' => 'Sample abstract for testing. A mobile app reminds households to remove standing water where mosquitoes breed.'],
            ['key' => 'synthetic_catalog_10', 'title' => 'BSIT Sample Thesis: Campus Energy Dashboard', 'year' => 2025, 'program' => 'BS Information Technology',
                'adviser' => 'Prof. Ramon Aguilar', 'keywords' => 'energy monitoring, dashboard, IoT', 'public' => 1, 'pages' => 105,
                'authors' => ['Lorraine Tan', 'Miguel Rivera'],
                'abstract' => 'Sample abstract for testing. A dashboard shows electricity use per building so facility staff can spot waste.'],
            ['key' => 'synthetic_catalog_11', 'title' => 'BSCS Sample Thesis: Traffic Light Simulation', 'year' => 2019, 'program' => 'BS Computer Science',
                'adviser' => 'Dr. Liza Manalo', 'keywords' => 'simulation, traffic, agent-based modeling', 'public' => 1, 'pages' => 95,
                'authors' => ['Daniel Ocampo'],
                'abstract' => 'Sample abstract for testing. A simulation of vehicle queues compares two traffic light timing plans.'],
            ['key' => 'synthetic_catalog_12', 'title' => 'BSIT Sample Thesis: Unpublished Draft', 'year' => 2026, 'program' => 'BS Information Technology',
                'adviser' => 'Prof. Ramon Aguilar', 'keywords' => 'unpublished, visibility', 'public' => 0, 'pages' => 50,
                'authors' => ['Andrea Morales'],
                'abstract' => 'Sample abstract for testing. This record stays unpublished so the public page can be checked for hidden records.'],
        ];

        $lookup = $pdo->prepare('SELECT id FROM theses WHERE fixture_key = :fixture_key');
        $insertThesis = $pdo->prepare("
            INSERT INTO theses (fixture_key, title, publication_year, program, adviser, abstract, keywords, page_count, is_public)
            VALUES (:fixture_key, :title, :year, :program, :adviser, :abstract, :keywords, :pages, :is_public)
        ");
        $insertAuthor = $pdo->prepare('INSERT INTO thesis_authors (thesis_id, author_name, author_order) VALUES (:thesis_id, :name, :position)');

        foreach ($records as $record) {
            // the fixture key lets repeated runs find a record that was already added and leave it unchanged
            $lookup->execute(['fixture_key' => $record['key']]);
            if ($lookup->fetch()) {
                continue;
            }
            $insertThesis->execute([
                'fixture_key' => $record['key'],
                'title' => $record['title'],
                'year' => $record['year'],
                'program' => $record['program'],
                'adviser' => $record['adviser'], // adviser is RDPO-only information; the public page never shows it
                'abstract' => $record['abstract'],
                'keywords' => $record['keywords'],
                'pages' => $record['pages'],
                'is_public' => $record['public'],
            ]);
            $thesisId = (int) $pdo->lastInsertId(); // ID of the thesis inserted immediately above
            foreach ($record['authors'] as $index => $name) {
                $position = $index + 1; // store author positions as 1, 2, ... rather than array indexes 0, 1, ...
                $insertAuthor->execute(['thesis_id' => $thesisId, 'name' => $name, 'position' => $position]);
            }
        }
    }
}
