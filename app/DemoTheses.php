<?php
// the purpose of this file is for loading (and later removing) a set of realistic-looking demo thesis records,
// so semantic search can be shown with abstracts of a normal length (user's request, 2026-11-10)
declare(strict_types=1); // keep typed method arguments strict

namespace Scholar;

use PDO;

// EVERY title, abstract, keyword list, author and adviser below is MADE UP for demonstrations; none of them is a real
// thesis, student or teacher, and nothing was copied from the reference theses
// each record has a fixture_key starting with "demo_", so "bin/console.php demo-data --remove" finds exactly these
class DemoTheses {
    private const KEY_PREFIX = 'demo_';

    // key => record; authors are [given names, last name]
    private const RECORDS = [
        'flood_alert' => [
            'title' => 'Barangay Flood Early Warning System Using Water-Level Sensors and SMS Alerts',
            'year' => 2025, 'defense' => '2025-04-22', 'program' => 'BS Information Technology', 'department' => 'College of Computer Studies', 'campus' => 'WCC QC', 'pages' => 112,
            'keywords' => 'flood monitoring, ultrasonic sensor, SMS notification, disaster preparedness, barangay',
            'authors' => [['Mark Anthony', 'Villanueva'], ['Kristine Joy', 'Bautista'], ['Jerome', 'Dizon']],
            'abstract' => 'Low-lying barangays often learn about rising floodwater only when it has already reached homes. This study developed an early warning system that measures the water level of a creek with ultrasonic sensors connected to a microcontroller and sends SMS alerts to registered residents and barangay officials when preset levels are reached. A web dashboard shows the readings in real time and keeps a history for disaster planning. The system was tested for four weeks during the rainy season and evaluated by residents and officials using ISO/IEC 25010 criteria, receiving an overall rating of "Very Acceptable". Respondents reported that the alerts gave them more time to move families and belongings to higher ground.',
        ],
        'evacuation_map' => [
            'title' => 'Web-Based Evacuation Center Locator and Capacity Tracker for Typhoon Response',
            'year' => 2024, 'defense' => '2024-05-08', 'program' => 'BS Information Technology', 'department' => 'College of Computer Studies', 'campus' => 'WCC Antipolo', 'pages' => 98,
            'keywords' => 'evacuation center, typhoon, web map, disaster risk reduction, capacity monitoring',
            'authors' => [['Patricia Mae', 'Soriano'], ['John Carlo', 'Mendoza']],
            'abstract' => 'During strong typhoons, families often go to evacuation centers that are already full. This study built a web-based map that shows the nearest evacuation centers, their remaining capacity, and the supplies available in each, updated by center staff through a simple mobile form. Residents can view the map without logging in, while the municipal disaster office sees a summary of all centers. The system was pilot-tested with three centers in a simulated typhoon drill. Staff updated capacity in under a minute, and residents found a center with space faster than with the previous phone hotline. The researchers recommend linking the system with official weather bulletins.',
        ],
        'jeepney_tracker' => [
            'title' => 'Real-Time Jeepney Arrival Estimator Using GPS and Crowdsourced Passenger Reports',
            'year' => 2025, 'defense' => '2025-05-14', 'program' => 'BS Information Technology', 'department' => 'College of Computer Studies', 'campus' => 'WCC Antipolo', 'pages' => 104,
            'keywords' => 'public transportation, GPS tracking, mobile application, commuters, estimated time of arrival',
            'authors' => [['Rafael', 'Domingo'], ['Angelica', 'Ramos'], ['Kevin', 'Lacson']],
            'abstract' => 'Commuters waiting for jeepneys rarely know when the next one will pass. This study developed a mobile application that estimates arrival times on one route by combining GPS readings from devices installed in participating jeepneys with reports from passengers. Drivers see how many passengers are waiting at each stop. Over two weeks of testing, estimated arrival times were within three minutes of the actual arrival in most trips. Commuters said the application reduced uncertainty and waiting time, and drivers used the waiting-passenger counts to plan their trips. The study recommends partnering with transport cooperatives to cover more routes.',
        ],
        'rice_blast' => [
            'title' => 'Detection of Rice Blast and Bacterial Leaf Blight from Smartphone Images Using a Convolutional Neural Network',
            'year' => 2025, 'defense' => '2025-03-28', 'program' => 'BS Computer Science', 'department' => 'College of Computer Studies', 'campus' => 'WCC Guimba', 'pages' => 126,
            'keywords' => 'deep learning, image classification, rice diseases, agriculture, mobile application',
            'authors' => [['Lorenzo', 'Pascual'], ['Mary Grace', 'Agustin']],
            'abstract' => 'Rice farmers in Nueva Ecija often notice crop diseases only after they have spread. This study trained a convolutional neural network to recognize rice blast, bacterial leaf blight, and healthy leaves from photos taken with ordinary smartphones in the field. A dataset of photos was collected with the help of local farmers and agricultural technicians, and the model was packaged in an offline mobile application. The model reached an accuracy of 91 percent on photos it had not seen before. Farmers who tried the application found it easy to use and said it helped them decide when to ask an agricultural technician for advice.',
        ],
        'taglish_reviews' => [
            'title' => 'Sentiment Analysis of Taglish Online Shopping Reviews Using a Fine-Tuned Language Model',
            'year' => 2024, 'defense' => '2024-04-30', 'program' => 'BS Computer Science', 'department' => 'College of Computer Studies', 'campus' => 'WCC QC', 'pages' => 118,
            'keywords' => 'natural language processing, sentiment analysis, code-switching, Filipino, e-commerce',
            'authors' => [['Bianca', 'Valdez'], ['Nathaniel', 'Cruz'], ['Sofia', 'Navarro']],
            'abstract' => 'Many Filipino shoppers write product reviews in Taglish, a mix of Tagalog and English that general sentiment tools often misread. This study collected and labeled online shopping reviews and fine-tuned a multilingual language model to classify them as positive, negative, or neutral. Its results were compared with a dictionary-based method and a model trained only on English text. The fine-tuned model performed best, especially on reviews with sarcasm and slang. A simple web tool lets small online sellers paste reviews and see the overall sentiment of their products. The study shares its labeling guide to help future research on code-switched text.',
        ],
        'fake_news' => [
            'title' => 'Identifying Misleading Health Posts in Filipino Social Media Using Text Classification',
            'year' => 2023, 'defense' => '2023-05-17', 'program' => 'BS Computer Science', 'department' => 'College of Computer Studies', 'campus' => 'WCC North Manila', 'pages' => 109,
            'keywords' => 'misinformation, text classification, social media, public health, machine learning',
            'authors' => [['Joshua', 'Manalo'], ['Ella Marie', 'Salazar']],
            'abstract' => 'False health claims spread quickly on Filipino social media, especially during disease outbreaks. This study gathered public posts in Filipino and English about common illnesses and vaccines, labeled them with the help of health professionals, and trained several classifiers to flag posts that are likely misleading. A logistic regression model with word and character features gave the best balance of accuracy and speed. The researchers built a browser extension that marks flagged posts and links to reliable health information. Users who tested the extension said it made them more careful before sharing posts, although the model still struggles with posts that mostly contain images.',
        ],
        'library_recommender' => [
            'title' => 'Book Recommendation System for an Academic Library Based on Borrowing History and Course Subjects',
            'year' => 2024, 'defense' => '2024-05-21', 'program' => 'BS Computer Science', 'department' => 'College of Computer Studies', 'campus' => 'WCC Binalonan', 'pages' => 97,
            'keywords' => 'recommender system, collaborative filtering, academic library, information retrieval',
            'authors' => [['Carlo Miguel', 'Fernandez'], ['Hazel', 'Ocampo']],
            'abstract' => 'Students often find only the books their teachers mention and miss other useful titles in the library. This study built a recommendation system that suggests books using anonymized borrowing records and the subjects each student is enrolled in. Collaborative filtering was combined with subject matching so that new students still receive useful suggestions. In a one-semester trial, librarians saw more borrowing of titles that had rarely been used, and students rated the suggestions as relevant most of the time. The study discusses how borrowing data was anonymized to protect student privacy.',
        ],
        'face_attendance' => [
            'title' => 'Classroom Attendance Monitoring Using Face Recognition on a Low-Cost Single-Board Computer',
            'year' => 2023, 'defense' => '2023-04-26', 'program' => 'BS Information Technology', 'department' => 'College of Computer Studies', 'campus' => 'WCC Guimba', 'pages' => 101,
            'keywords' => 'face recognition, attendance system, single-board computer, data privacy',
            'authors' => [['Dennis', 'Aquino'], ['Rowena', 'Santiago'], ['Paolo', 'Reyes']],
            'abstract' => 'Calling the roll takes several minutes of every class. This study developed an attendance system that recognizes enrolled students as they enter the room using a camera connected to a low-cost single-board computer, and sends the attendance list to the teacher\'s account. Students gave written consent, and face data is stored only as numeric templates on the device. In testing with two sections, the system recognized students correctly in good lighting but needed better lights near the door. Teachers saved time at the start of class and could still correct the list by hand.',
        ],
        'waste_app' => [
            'title' => 'A Gamified Mobile Application for Promoting Household Waste Segregation',
            'year' => 2025, 'defense' => '2025-04-09', 'program' => 'BS Information Technology', 'department' => 'College of Computer Studies', 'campus' => 'WCC North Manila', 'pages' => 93,
            'keywords' => 'gamification, solid waste management, recycling, behavior change, mobile application',
            'authors' => [['Jasmine', 'Gutierrez'], ['Miguel', 'Torres']],
            'abstract' => 'Many households still mix biodegradable, recyclable, and residual waste despite local ordinances. This study developed a mobile application that teaches proper segregation through short quizzes, daily tasks, and points that can be exchanged for small rewards from partner stores. Barangay collectors confirm segregated bags by scanning a household QR code. After a six-week trial with sixty households, the share of properly segregated bags increased, and residents said the reminders and friendly competition kept them motivated. The study suggests involving schools to reach more families.',
        ],
        'classroom_air' => [
            'title' => 'Indoor Air Quality and Temperature Monitoring System for Non-Air-Conditioned Classrooms',
            'year' => 2026, 'defense' => '2026-03-19', 'program' => 'BS Information Technology', 'department' => 'College of Computer Studies', 'campus' => 'WCC QC', 'pages' => 88,
            'keywords' => 'Internet of Things, carbon dioxide, heat index, classroom environment, dashboard',
            'authors' => [['Andrea', 'Castillo'], ['Ivan', 'Morales']],
            'abstract' => 'Crowded classrooms without air conditioning can become hot and stuffy, which affects students\' focus. This study built sensor units that measure carbon dioxide, temperature, and humidity in classrooms and show the readings on a dashboard for teachers and the facilities office. When readings pass recommended limits, the dashboard suggests opening windows, turning on fans, or taking a short break. Measurements over one month showed that rooms became uncomfortable mostly in the early afternoon. Teachers found the alerts useful, and the facilities office used the data to decide where to add ventilation.',
        ],
        'enrollment_queue' => [
            'title' => 'Online Queue and Appointment System for Student Enrollment Services',
            'year' => 2024, 'defense' => '2024-04-17', 'program' => 'BS Information Technology', 'department' => 'College of Computer Studies', 'campus' => 'WCC North Manila', 'pages' => 95,
            'keywords' => 'queue management, appointment scheduling, enrollment, student services, web application',
            'authors' => [['Samantha', 'Del Rosario'], ['Bryan', 'Gonzales']],
            'abstract' => 'Long lines at the registrar and cashier during enrollment week cause students to wait for hours. This study developed a web system where students book a time slot for each office, receive a queue number on their phone, and get a notice when their turn is near. Staff see the day\'s appointments and can call the next student with one click. During a trial enrollment period, average waiting time on campus dropped noticeably, and both students and staff rated the system highly for ease of use. The researchers recommend adding online payment to shorten the process further.',
        ],
        'working_students_sleep' => [
            'title' => 'Academic Stress, Sleep Quality, and Coping Strategies of Working College Students',
            'year' => 2024, 'defense' => '2024-05-02', 'program' => 'BS Psychology', 'department' => 'College of Arts and Sciences', 'campus' => 'WCC QC', 'pages' => 87,
            'keywords' => 'academic stress, sleep quality, coping, working students, correlational study',
            'authors' => [['Camille', 'Robles'], ['Joanna Marie', 'Tan'], ['Elijah', 'Vergara']],
            'abstract' => 'Many college students work part-time or full-time to pay for their studies. This correlational study surveyed working students about their academic stress, sleep quality, and ways of coping. Results showed that higher academic stress was linked with poorer sleep, and that students who relied on planning and seeking support reported better sleep than those who avoided their problems. Students working night shifts had the lowest sleep quality. The study recommends flexible deadlines for working students and a peer support program run by the guidance office.',
        ],
        'social_media_loneliness' => [
            'title' => 'Social Media Use, Loneliness, and Self-Esteem Among Senior High School Students',
            'year' => 2023, 'defense' => '2023-05-10', 'program' => 'BS Psychology', 'department' => 'College of Arts and Sciences', 'campus' => 'WCC Antipolo', 'pages' => 82,
            'keywords' => 'social media, loneliness, self-esteem, adolescents, survey research',
            'authors' => [['Nicole', 'Lim'], ['Adrian', 'Valencia']],
            'abstract' => 'Teenagers spend many hours on social media, yet some still feel alone. This study surveyed senior high school students about how they use social media, how lonely they feel, and how they see themselves. Passive scrolling was associated with more loneliness and lower self-esteem, while chatting with close friends was not. Students who compared themselves often with others online reported the lowest self-esteem. The researchers recommend lessons on mindful social media use and stronger offline activities in school clubs.',
        ],
        'cyberbullying' => [
            'title' => 'Lived Experiences of College Students Who Experienced Cyberbullying: A Phenomenological Study',
            'year' => 2025, 'defense' => '2025-05-06', 'program' => 'BS Psychology', 'department' => 'College of Arts and Sciences', 'campus' => 'WCC Binalonan', 'pages' => 91,
            'keywords' => 'cyberbullying, lived experience, phenomenology, coping, mental health',
            'authors' => [['Trisha Ann', 'Mercado'], ['Gabriel', 'Ignacio']],
            'abstract' => 'This qualitative study explored what it is like for college students to be harassed online. Eight participants shared their experiences in in-depth interviews, which were analyzed through interpretative phenomenological analysis. Four themes emerged: feeling exposed and powerless, withdrawing from friends and classes, searching for someone to trust, and slowly regaining control. Participants valued teachers and friends who believed them and helped them report the harassment. The study recommends clear reporting procedures and counseling services that respond quickly to online harassment cases.',
        ],
        'peer_tutoring_math' => [
            'title' => 'Effect of Peer Tutoring on the Mathematics Anxiety of First-Year College Students',
            'year' => 2026, 'defense' => '2026-02-26', 'program' => 'BS Psychology', 'department' => 'College of Arts and Sciences', 'campus' => 'WCC Guimba', 'pages' => 79,
            'keywords' => 'math anxiety, peer tutoring, quasi-experimental, first-year students',
            'authors' => [['Rhea', 'Pangilinan'], ['Victor', 'Esguerra']],
            'abstract' => 'Fear of mathematics makes some first-year students avoid asking questions and fall behind. This quasi-experimental study compared students who attended weekly peer tutoring sessions for eight weeks with students who did not. Math anxiety was measured before and after the sessions. The tutored group showed a larger decrease in anxiety and slightly better quiz scores. Students said tutors were easier to approach than teachers and explained problems step by step. The study recommends training upper-year students as tutors in general education math courses.',
        ],
        'dengue_knowledge' => [
            'title' => 'Knowledge, Attitudes, and Practices on Dengue Prevention Among Households in an Urban Barangay',
            'year' => 2024, 'defense' => '2024-04-24', 'program' => 'BS Nursing', 'department' => 'College of Nursing', 'campus' => 'WCC QC', 'pages' => 94,
            'keywords' => 'dengue, mosquito control, community health, health education, survey',
            'authors' => [['Maria Luisa', 'Padilla'], ['Arvin', 'Santos'], ['Clarisse', 'Yap']],
            'abstract' => 'Dengue cases rise every rainy season in crowded urban communities. This descriptive study surveyed households about their knowledge of dengue, their attitudes toward prevention, and what they actually do to control mosquitoes. Most respondents knew the signs of dengue, but fewer regularly cleaned water containers or covered stored water. Households that had attended a barangay health session showed better practices. The researchers prepared an education plan for barangay health workers focused on weekly search-and-destroy activities for mosquito breeding sites.',
        ],
        'hand_hygiene' => [
            'title' => 'Hand Hygiene Compliance of Nursing Students During Clinical Duty',
            'year' => 2025, 'defense' => '2025-03-12', 'program' => 'BS Nursing', 'department' => 'College of Nursing', 'campus' => 'WCC North Manila', 'pages' => 76,
            'keywords' => 'hand hygiene, infection control, nursing students, clinical practice, observation',
            'authors' => [['Erika', 'De Guzman'], ['Joseph', 'Alcantara']],
            'abstract' => 'Clean hands are one of the most effective ways to prevent infections in hospitals. This study observed nursing students during their clinical rotations using the five moments of hand hygiene and asked them about the reasons for missed opportunities. Compliance was highest before procedures and lowest after touching patient surroundings. Students cited heavy workloads, lack of nearby sanitizer, and forgetting as the main reasons. After posters and sanitizer stations were added, compliance improved during the follow-up observation. The study recommends regular feedback from clinical instructors.',
        ],
        'hypertension_elderly' => [
            'title' => 'Medication Adherence and Family Support Among Elderly Patients with Hypertension',
            'year' => 2023, 'defense' => '2023-04-19', 'program' => 'BS Nursing', 'department' => 'College of Nursing', 'campus' => 'WCC Binalonan', 'pages' => 85,
            'keywords' => 'hypertension, medication adherence, older adults, family support, primary care',
            'authors' => [['Kathleen', 'Ferrer'], ['Ramon', 'Bernardo']],
            'abstract' => 'Many older adults with high blood pressure do not take their medicines as prescribed. This study surveyed elderly patients at a rural health unit about how regularly they take their maintenance medicines and how much support they receive from family members. Patients who lived with family members who reminded them or bought their medicines showed better adherence. Cost and forgetting were the most common reasons for missed doses. The study recommends involving family members in health teaching and using simple pill organizers.',
        ],
        'breastfeeding_app' => [
            'title' => 'Effectiveness of a Mobile Breastfeeding Support Program for First-Time Mothers',
            'year' => 2026, 'defense' => '2026-03-05', 'program' => 'BS Nursing', 'department' => 'College of Nursing', 'campus' => 'WCC Antipolo', 'pages' => 90,
            'keywords' => 'breastfeeding, maternal health, mobile health, health education, newborn care',
            'authors' => [['Shaira', 'Navarro'], ['Daniel', 'Cabrera']],
            'abstract' => 'First-time mothers often stop breastfeeding early because of pain, worry about milk supply, or lack of guidance at home. This study gave first-time mothers access to a mobile program with short videos, daily tips, and a chat line to a nurse during the first six weeks after giving birth. Compared with mothers who received only the usual hospital teaching, mothers in the program were more likely to still be exclusively breastfeeding at six weeks and felt more confident. The study recommends adding the program to postnatal care in community health centers.',
        ],
        'sari_sari_bookkeeping' => [
            'title' => 'Digital Bookkeeping Adoption Among Sari-Sari Store Owners',
            'year' => 2025, 'defense' => '2025-04-15', 'program' => 'BS Business Administration', 'department' => 'College of Business Administration', 'campus' => 'WCC Binalonan', 'pages' => 84,
            'keywords' => 'micro-enterprise, bookkeeping, technology adoption, small retail, financial records',
            'authors' => [['Lovely', 'Concepcion'], ['Mark', 'Rivera']],
            'abstract' => 'Neighborhood sari-sari stores usually record sales and credit in notebooks, if at all. This study surveyed and interviewed store owners about their use of bookkeeping applications on their phones. Owners who used an application were more likely to know their monthly profit and to track customers\' unpaid credit. The main barriers to adoption were unfamiliarity with the applications, fear of losing records, and lack of time. The researchers recommend short, hands-on training sessions organized with local cooperatives and barangay offices.',
        ],
        'ewallet_vendors' => [
            'title' => 'Effects of E-Wallet Payment Adoption on the Sales of Small Food Vendors',
            'year' => 2024, 'defense' => '2024-05-15', 'program' => 'BS Business Administration', 'department' => 'College of Business Administration', 'campus' => 'WCC QC', 'pages' => 80,
            'keywords' => 'digital payments, e-wallet, street food vendors, cashless transactions, small business',
            'authors' => [['Jenny', 'Sarmiento'], ['Christian', 'Abad']],
            'abstract' => 'Street food vendors near schools and offices increasingly accept payments through e-wallets. This study compared the daily sales and customer counts of vendors before and after they started accepting e-wallet payments, and interviewed them about the benefits and problems. Most vendors reported more customers, especially students without cash, and faster service. Some worried about transaction fees, weak mobile signal, and keeping track of their balance. The study recommends that local governments help vendors set up e-wallet accounts and learn basic digital record-keeping.',
        ],
        'bpo_turnover' => [
            'title' => 'Factors Influencing Employee Turnover Intention in Business Process Outsourcing Companies',
            'year' => 2023, 'defense' => '2023-05-24', 'program' => 'BS Business Administration', 'department' => 'College of Business Administration', 'campus' => 'WCC North Manila', 'pages' => 92,
            'keywords' => 'turnover intention, call center, job satisfaction, work schedule, human resource management',
            'authors' => [['Anne Margaret', 'Lopez'], ['Kenneth', 'Bautista'], ['Diane', 'Pineda']],
            'abstract' => 'Call center companies lose many agents within their first year. This study surveyed agents from several outsourcing companies about their job satisfaction, workload, night shifts, pay, and plans to leave. Workload, irregular schedules, and limited chances for promotion had the strongest relationship with plans to resign, while pay mattered less than expected. Agents with supportive team leaders were more likely to stay. The study recommends clearer career paths, fairer shift rotation, and training for team leaders on coaching and feedback.',
        ],
        'milk_tea_satisfaction' => [
            'title' => 'Service Quality and Customer Loyalty in Milk Tea Shops Near Universities',
            'year' => 2025, 'defense' => '2025-05-20', 'program' => 'BS Business Administration', 'department' => 'College of Business Administration', 'campus' => 'WCC Guimba', 'pages' => 77,
            'keywords' => 'service quality, customer loyalty, SERVQUAL, food and beverage, students',
            'authors' => [['Princess', 'Dela Cruz'], ['Harold', 'Ventura']],
            'abstract' => 'Milk tea shops compete closely for student customers. Using the SERVQUAL model, this study surveyed customers of milk tea shops near universities about the service they received and whether they would return and recommend the shop. Reliability and responsiveness, such as getting the right order quickly, had the strongest link to loyalty, more than the look of the shop. Price promotions brought new customers but did not keep them. The researchers recommend staff training on order accuracy and a simple loyalty card.',
        ],
        'festival_tourism' => [
            'title' => 'Social Media Marketing and Its Influence on Visitor Interest in Local Town Festivals',
            'year' => 2026, 'defense' => '2026-03-26', 'program' => 'BS Business Administration', 'department' => 'College of Business Administration', 'campus' => 'WCC Antipolo', 'pages' => 83,
            'keywords' => 'tourism marketing, social media, local festivals, destination image, visitor intention',
            'authors' => [['Aira', 'Manuel'], ['Jericho', 'Santos']],
            'abstract' => 'Town festivals can attract visitors and support local businesses, but many are known only to nearby residents. This study analyzed the social media pages of several town festivals and surveyed people who saw their posts. Short videos showing food, street dancing, and local products created the most interest in visiting, while plain announcements did not. Respondents trusted posts shared by friends more than paid advertisements. The study recommends that tourism offices plan their posts early and work with local content creators.',
        ],
    ];

    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // how many demo records there are in the list above
    public static function count(): int {
        return count(self::RECORDS);
    }

    // the list itself, for the unit test that checks every demo record is complete and believable
    public static function records(): array {
        return self::RECORDS;
    }

    // adds the demo records that are not in the database yet (running it twice adds nothing twice)
    // returns the ids of the records it added; all are published so the public search can show them
    public function install(): array {
        $lookup = $this->pdo->prepare('SELECT id FROM theses WHERE fixture_key = :fixture_key');
        $insertThesis = $this->pdo->prepare("
            INSERT INTO theses (fixture_key, title, publication_year, program, department, campus, adviser, abstract, keywords,
                                final_defense_date, page_count, is_public)
            VALUES (:fixture_key, :title, :year, :program, :department, :campus, NULL, :abstract, :keywords, :defense, :pages, 1)
        ");
        $insertAuthor = $this->pdo->prepare("
            INSERT INTO thesis_authors (thesis_id, author_name, given_names, last_name, author_order)
            VALUES (:thesis_id, :full_name, :given_names, :last_name, :position)
        ");
        $added = [];
        foreach (self::RECORDS as $key => $record) {
            $fixtureKey = self::KEY_PREFIX . $key;
            $lookup->execute(['fixture_key' => $fixtureKey]);
            if ($lookup->fetchColumn()) {
                continue; // already loaded
            }
            // the record and its authors are saved together; if either fails, neither is kept
            $this->pdo->beginTransaction();
            try {
                $insertThesis->execute([
                    'fixture_key' => $fixtureKey, 'title' => $record['title'], 'year' => $record['year'],
                    'program' => $record['program'], 'department' => $record['department'], 'campus' => $record['campus'],
                    'abstract' => $record['abstract'], 'keywords' => $record['keywords'], 'defense' => $record['defense'],
                    'pages' => $record['pages'],
                ]);
                $thesisId = (int) $this->pdo->lastInsertId();
                foreach ($record['authors'] as $index => $author) {
                    $insertAuthor->execute([
                        'thesis_id' => $thesisId, 'full_name' => $author[0] . ' ' . $author[1],
                        'given_names' => $author[0], 'last_name' => $author[1], 'position' => $index + 1,
                    ]);
                }
                $this->pdo->commit();
            } catch (\Throwable $exception) {
                $this->pdo->rollBack();
                throw $exception;
            }
            $added[] = $thesisId;
        }
        return $added;
    }

    // deletes every demo record (and its authors and history); returns the ids that were removed
    // records RDPO added by hand are never touched, because only fixture keys starting with "demo_" are matched
    public function remove(): array {
        // LIKE 'demo\_%': the backslash makes "_" a real underscore instead of "any one character"
        $ids = array_map('intval', $this->pdo->query("SELECT id FROM theses WHERE fixture_key LIKE 'demo\\_%'")->fetchAll(PDO::FETCH_COLUMN));
        foreach ($ids as $id) {
            $this->pdo->beginTransaction();
            try {
                $this->pdo->prepare("DELETE FROM audit_logs WHERE entity_type = 'thesis' AND entity_id = :id")->execute(['id' => $id]);
                $this->pdo->prepare('DELETE FROM thesis_authors WHERE thesis_id = :id')->execute(['id' => $id]);
                $this->pdo->prepare('DELETE FROM theses WHERE id = :id')->execute(['id' => $id]);
                $this->pdo->commit();
            } catch (\Throwable $exception) {
                $this->pdo->rollBack();
                throw $exception;
            }
        }
        return $ids;
    }
}
