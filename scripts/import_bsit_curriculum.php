<?php
/**
 * BSIT Curriculum Subject Import & Excel Generator
 * 
 * Extracts all subjects from the BSIT curriculum,
 * inserts them into the database (linked to BSIT Curriculum id=7),
 * and generates an Excel file.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;

// ── Database connection ───────────────────────────────────────────────
$pdo = new PDO('mysql:host=127.0.0.1;dbname=evaluation_db;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$CURRICULUM_ID = 7;    // BSIT Curriculum 2026
$DEPARTMENT_ID = 1;    // INFORMATION TECHNOLOGY

// ── Full BSIT Curriculum Data ─────────────────────────────────────────
$curriculum = [
    // ─── FIRST YEAR ───
    ['year' => 'First Year', 'sem' => '1st Semester', 'code' => 'GE 4',    'name' => 'Mathematics in the Modern World',           'lec' => 3, 'lab' => 0, 'units' => 3],
    ['year' => 'First Year', 'sem' => '1st Semester', 'code' => 'GE 5',    'name' => 'Purposive Communication',                   'lec' => 3, 'lab' => 0, 'units' => 3],
    ['year' => 'First Year', 'sem' => '1st Semester', 'code' => 'GE 6',    'name' => 'Art Appreciation',                          'lec' => 3, 'lab' => 0, 'units' => 3],
    ['year' => 'First Year', 'sem' => '1st Semester', 'code' => 'FL 1',    'name' => 'Akademiko sa Wikang Filipino',               'lec' => 3, 'lab' => 0, 'units' => 3],
    ['year' => 'First Year', 'sem' => '1st Semester', 'code' => 'ITS 100', 'name' => 'Introduction to Computing',                 'lec' => 3, 'lab' => 0, 'units' => 3],
    ['year' => 'First Year', 'sem' => '1st Semester', 'code' => 'ITS 101', 'name' => 'Computer Programming I',                    'lec' => 2, 'lab' => 1, 'units' => 3],
    ['year' => 'First Year', 'sem' => '1st Semester', 'code' => 'PE 1',    'name' => 'Physical Education I',                      'lec' => 2, 'lab' => 0, 'units' => 2],
    ['year' => 'First Year', 'sem' => '1st Semester', 'code' => 'NSTP 1',  'name' => 'National Service Training Program I',       'lec' => 3, 'lab' => 0, 'units' => 3],

    ['year' => 'First Year', 'sem' => '2nd Semester', 'code' => 'GE 1',    'name' => 'Understanding the Self',                    'lec' => 3, 'lab' => 0, 'units' => 3],
    ['year' => 'First Year', 'sem' => '2nd Semester', 'code' => 'GE 2',    'name' => 'Readings in Philippine History',            'lec' => 3, 'lab' => 0, 'units' => 3],
    ['year' => 'First Year', 'sem' => '2nd Semester', 'code' => 'GE 3',    'name' => 'The Contemporary World',                    'lec' => 3, 'lab' => 0, 'units' => 3],
    ['year' => 'First Year', 'sem' => '2nd Semester', 'code' => 'IT 1',    'name' => 'Filipino Literature',                       'lec' => 3, 'lab' => 0, 'units' => 3],
    ['year' => 'First Year', 'sem' => '2nd Semester', 'code' => 'ITS 103', 'name' => 'Discrete Mathematics',                      'lec' => 3, 'lab' => 0, 'units' => 3],
    ['year' => 'First Year', 'sem' => '2nd Semester', 'code' => 'ITS 104', 'name' => 'Computer Programming II',                   'lec' => 2, 'lab' => 1, 'units' => 3],
    ['year' => 'First Year', 'sem' => '2nd Semester', 'code' => 'ITS 105', 'name' => 'Data Structures & Algorithms',              'lec' => 3, 'lab' => 0, 'units' => 3],
    ['year' => 'First Year', 'sem' => '2nd Semester', 'code' => 'PE 2',    'name' => 'Recreational Games and Sports',             'lec' => 2, 'lab' => 0, 'units' => 2],
    ['year' => 'First Year', 'sem' => '2nd Semester', 'code' => 'NSTP 2',  'name' => 'National Service Training Program II',      'lec' => 3, 'lab' => 0, 'units' => 3],

    // Enhancement
    ['year' => 'First Year', 'sem' => 'Enhancement',  'code' => 'EN PRECAL','name' => 'Pre-Calculus',                              'lec' => 3, 'lab' => 0, 'units' => 3],
    ['year' => 'First Year', 'sem' => 'Enhancement',  'code' => 'EN BACAL', 'name' => 'Basic Calculus',                            'lec' => 3, 'lab' => 0, 'units' => 3],

    // ─── SECOND YEAR ───
    ['year' => 'Second Year', 'sem' => '1st Semester', 'code' => 'GE 7',    'name' => 'Science, Technology and Society',          'lec' => 3, 'lab' => 0, 'units' => 3],
    ['year' => 'Second Year', 'sem' => '1st Semester', 'code' => 'GE 8',    'name' => 'Ethics',                                   'lec' => 3, 'lab' => 0, 'units' => 3],
    ['year' => 'Second Year', 'sem' => '1st Semester', 'code' => 'GE 9',    'name' => 'Life and Works of Rizal',                  'lec' => 3, 'lab' => 0, 'units' => 3],
    ['year' => 'Second Year', 'sem' => '1st Semester', 'code' => 'Eng 127', 'name' => 'Business Communication I',                 'lec' => 3, 'lab' => 0, 'units' => 3],
    ['year' => 'Second Year', 'sem' => '1st Semester', 'code' => 'ITS 200', 'name' => 'Database Mgt. Systems I',                  'lec' => 3, 'lab' => 0, 'units' => 3],
    ['year' => 'Second Year', 'sem' => '1st Semester', 'code' => 'ITS 201', 'name' => 'Systems Integration & Architecture',       'lec' => 2, 'lab' => 1, 'units' => 3],
    ['year' => 'Second Year', 'sem' => '1st Semester', 'code' => 'ITS 202', 'name' => 'Object Oriented Programming I',            'lec' => 2, 'lab' => 1, 'units' => 3],
    ['year' => 'Second Year', 'sem' => '1st Semester', 'code' => 'ITS 203', 'name' => 'Intellectual Property Basic',              'lec' => 3, 'lab' => 0, 'units' => 3],
    ['year' => 'Second Year', 'sem' => '1st Semester', 'code' => 'PE 3',    'name' => 'Rhythmic and Social Recreation',           'lec' => 2, 'lab' => 0, 'units' => 2],

    ['year' => 'Second Year', 'sem' => '2nd Semester', 'code' => 'GE 10',   'name' => 'Environmental Science',                    'lec' => 3, 'lab' => 0, 'units' => 3],
    ['year' => 'Second Year', 'sem' => '2nd Semester', 'code' => 'Eng 128', 'name' => 'Business Communication II',                'lec' => 3, 'lab' => 0, 'units' => 3],
    ['year' => 'Second Year', 'sem' => '2nd Semester', 'code' => 'ITS 204', 'name' => 'Hardware and Software Installation',       'lec' => 2, 'lab' => 1, 'units' => 3],
    ['year' => 'Second Year', 'sem' => '2nd Semester', 'code' => 'ITS 205', 'name' => 'Database Mgt. Systems II',                 'lec' => 2, 'lab' => 1, 'units' => 3],
    ['year' => 'Second Year', 'sem' => '2nd Semester', 'code' => 'ITS 206', 'name' => 'Data Comm. & Networking I',                'lec' => 2, 'lab' => 1, 'units' => 3],
    ['year' => 'Second Year', 'sem' => '2nd Semester', 'code' => 'ITS 207', 'name' => 'Object Oriented Programming II',           'lec' => 2, 'lab' => 1, 'units' => 3],
    ['year' => 'Second Year', 'sem' => '2nd Semester', 'code' => 'ITS 209', 'name' => 'Introduction to Human Computer Interaction','lec' => 2, 'lab' => 1, 'units' => 3],
    ['year' => 'Second Year', 'sem' => '2nd Semester', 'code' => 'PE 4',    'name' => 'Cultural Presentation & Sports Management','lec' => 2, 'lab' => 0, 'units' => 2],

    // ─── THIRD YEAR ───
    ['year' => 'Third Year', 'sem' => '1st Semester', 'code' => 'GE 11',    'name' => 'Gender and Society',                       'lec' => 3, 'lab' => 0, 'units' => 3],
    ['year' => 'Third Year', 'sem' => '1st Semester', 'code' => 'BPO 1',    'name' => 'Fundamentals of BPO 1',                    'lec' => 3, 'lab' => 0, 'units' => 3],
    ['year' => 'Third Year', 'sem' => '1st Semester', 'code' => 'SERV 100', 'name' => 'Service Culture',                          'lec' => 3, 'lab' => 0, 'units' => 3],
    ['year' => 'Third Year', 'sem' => '1st Semester', 'code' => 'SYS 106',  'name' => 'Principles of Systems Thinking',           'lec' => 3, 'lab' => 0, 'units' => 3],
    ['year' => 'Third Year', 'sem' => '1st Semester', 'code' => 'ITS 300',  'name' => 'Web Development 1',                        'lec' => 2, 'lab' => 1, 'units' => 3],
    ['year' => 'Third Year', 'sem' => '1st Semester', 'code' => 'ITS 301',  'name' => 'Seminars & Field Trips',                   'lec' => 3, 'lab' => 0, 'units' => 3],
    ['year' => 'Third Year', 'sem' => '1st Semester', 'code' => 'ITS 302',  'name' => 'Data Comm. & Networking II',               'lec' => 2, 'lab' => 1, 'units' => 3],
    ['year' => 'Third Year', 'sem' => '1st Semester', 'code' => 'ITS 303',  'name' => 'Animation',                                'lec' => 2, 'lab' => 1, 'units' => 3],
    ['year' => 'Third Year', 'sem' => '1st Semester', 'code' => 'ITS 304',  'name' => 'System Analysis & Design',                 'lec' => 2, 'lab' => 1, 'units' => 3],

    ['year' => 'Third Year', 'sem' => '2nd Semester', 'code' => 'GE 12',    'name' => 'Philippine Popular Culture',               'lec' => 3, 'lab' => 0, 'units' => 3],
    ['year' => 'Third Year', 'sem' => '2nd Semester', 'code' => 'BPO 2',    'name' => 'Fundamentals of BPO 2',                    'lec' => 3, 'lab' => 0, 'units' => 3],
    ['year' => 'Third Year', 'sem' => '2nd Semester', 'code' => 'ITS 305',  'name' => 'Application Development and Emerging Technologies', 'lec' => 2, 'lab' => 1, 'units' => 3],
    ['year' => 'Third Year', 'sem' => '2nd Semester', 'code' => 'ITS 306',  'name' => 'Web Development 2',                        'lec' => 2, 'lab' => 1, 'units' => 3],
    ['year' => 'Third Year', 'sem' => '2nd Semester', 'code' => 'ITS 307',  'name' => 'Platform Technologies',                    'lec' => 2, 'lab' => 1, 'units' => 3],
    ['year' => 'Third Year', 'sem' => '2nd Semester', 'code' => 'ITS 308',  'name' => 'System Administration and Maintenance',    'lec' => 2, 'lab' => 1, 'units' => 3],
    ['year' => 'Third Year', 'sem' => '2nd Semester', 'code' => 'ITS 309',  'name' => 'Information Assurance & Security',         'lec' => 2, 'lab' => 1, 'units' => 3],
    ['year' => 'Third Year', 'sem' => '2nd Semester', 'code' => 'ITS 310',  'name' => 'Management Information System',            'lec' => 2, 'lab' => 1, 'units' => 3],
    ['year' => 'Third Year', 'sem' => '2nd Semester', 'code' => 'ITS 311',  'name' => 'Quantitative Method (Inc. Modeling & Simulation)', 'lec' => 2, 'lab' => 1, 'units' => 3],

    // Summer
    ['year' => 'Third Year', 'sem' => 'Summer',       'code' => 'ITS 400',  'name' => 'Internship 1 (300 hours)',                 'lec' => 3, 'lab' => 0, 'units' => 3],

    // ─── FOURTH YEAR ───
    ['year' => 'Fourth Year', 'sem' => '1st Semester', 'code' => 'ITS 401', 'name' => 'Internship 2 (500 hours)',                 'lec' => 3, 'lab' => 0, 'units' => 3],
    ['year' => 'Fourth Year', 'sem' => '1st Semester', 'code' => 'ITS 402', 'name' => 'Capstone Project 1',                       'lec' => 3, 'lab' => 0, 'units' => 3],

    ['year' => 'Fourth Year', 'sem' => '2nd Semester', 'code' => 'ITS 403', 'name' => 'Capstone Project 2',                       'lec' => 3, 'lab' => 0, 'units' => 3],
    ['year' => 'Fourth Year', 'sem' => '2nd Semester', 'code' => 'ITS 404', 'name' => 'Social and Professional Issues of IT',     'lec' => 3, 'lab' => 0, 'units' => 3],
    ['year' => 'Fourth Year', 'sem' => '2nd Semester', 'code' => 'ITS 405', 'name' => 'Multimedia Systems',                       'lec' => 3, 'lab' => 0, 'units' => 3],

    // Graphics & Visual Computing (2nd year 2nd sem - listed in image)
    ['year' => 'Second Year', 'sem' => '2nd Semester', 'code' => 'ITS 208', 'name' => 'Graphics & Visual Computing',              'lec' => 2, 'lab' => 1, 'units' => 3],
];

// ══════════════════════════════════════════════════════════════════════
// STEP 1:  Clean old test subjects & insert all curriculum subjects
// ══════════════════════════════════════════════════════════════════════
echo "=== STEP 1: Database Import ===\n";

// Delete old curriculum_subject links for curriculum 7
$pdo->exec("DELETE FROM curriculum_subject WHERE curriculum_id = $CURRICULUM_ID");
echo "  Cleared old curriculum_subject links.\n";

// Delete old subjects that were test data (IDs 5-11)
// Remove evaluation_response referencing them
$pdo->exec("DELETE FROM evaluation_response WHERE subject_id IN (SELECT id FROM subject WHERE id BETWEEN 5 AND 11)");
// Now delete the subjects
$pdo->exec("DELETE FROM subject WHERE id BETWEEN 5 AND 11");
echo "  Cleared old test subjects (IDs 5-11).\n";

// Reset auto-increment
$maxId = $pdo->query("SELECT COALESCE(MAX(id), 0) FROM subject")->fetchColumn();
$pdo->exec("ALTER TABLE subject AUTO_INCREMENT = " . ($maxId + 1));

// Insert subjects
$insertSubject = $pdo->prepare("
    INSERT INTO subject (subject_code, subject_name, semester, year_level, units, school_year, department_id, faculty_id)
    VALUES (:code, :name, :sem, :year, :units, '2026', NULL, NULL)
");

$insertLink = $pdo->prepare("
    INSERT INTO curriculum_subject (curriculum_id, subject_id)
    VALUES (:cid, :sid)
");

$inserted = 0;
$subjectIds = [];

foreach ($curriculum as $row) {
    // Map semester
    $sem = $row['sem'];
    
    $insertSubject->execute([
        'code'  => $row['code'],
        'name'  => $row['name'],
        'sem'   => $sem,
        'year'  => $row['year'],
        'units' => $row['units'],
    ]);
    
    $subjectId = (int) $pdo->lastInsertId();
    $subjectIds[] = $subjectId;
    
    // Link to BSIT curriculum
    $insertLink->execute([
        'cid' => $CURRICULUM_ID,
        'sid' => $subjectId,
    ]);
    
    $inserted++;
    echo "  [$inserted] {$row['code']} - {$row['name']} (ID: $subjectId)\n";
}

echo "\n  Total subjects inserted: $inserted\n";
echo "  All linked to BSIT Curriculum (ID: $CURRICULUM_ID)\n\n";

// ══════════════════════════════════════════════════════════════════════
// STEP 2:  Generate Excel File
// ══════════════════════════════════════════════════════════════════════
echo "=== STEP 2: Generating Excel ===\n";

$spreadsheet = new Spreadsheet();

// ── Styles ───
$headerStyle = [
    'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1a3a8a']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
];
$yearHeaderStyle = [
    'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2563eb']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
];
$semHeaderStyle = [
    'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => '1a3a8a']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'dbeafe']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
];
$dataStyle = [
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
];
$totalStyle = [
    'font' => ['bold' => true],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'f0f9ff']],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
];

$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('BSIT Curriculum');

// Column widths
$sheet->getColumnDimension('A')->setWidth(5);   // #
$sheet->getColumnDimension('B')->setWidth(14);  // Subject Code
$sheet->getColumnDimension('C')->setWidth(52);  // Descriptive Title
$sheet->getColumnDimension('D')->setWidth(8);   // Lec
$sheet->getColumnDimension('E')->setWidth(8);   // Lab
$sheet->getColumnDimension('F')->setWidth(10);  // Units

// Title row
$row = 1;
$sheet->mergeCells("A{$row}:F{$row}");
$sheet->setCellValue("A{$row}", 'BSIT CURRICULUM 2026 — INFORMATION TECHNOLOGY');
$sheet->getStyle("A{$row}:F{$row}")->applyFromArray([
    'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => '1a3a8a']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);
$sheet->getRowDimension($row)->setRowHeight(30);

// Header row
$row = 3;
$headers = ['#', 'Subject Code', 'Descriptive Title', 'Lec', 'Lab', 'Units'];
foreach ($headers as $i => $h) {
    $col = chr(65 + $i); // A, B, C, D, E, F
    $sheet->setCellValue("{$col}{$row}", $h);
}
$sheet->getStyle("A{$row}:F{$row}")->applyFromArray($headerStyle);
$sheet->getRowDimension($row)->setRowHeight(25);

// Group by year, then semester
$grouped = [];
foreach ($curriculum as $s) {
    $grouped[$s['year']][$s['sem']][] = $s;
}

$row = 4;
$num = 1;
$grandTotalLec = 0;
$grandTotalLab = 0;
$grandTotalUnits = 0;

foreach ($grouped as $year => $semesters) {
    // Year header
    $sheet->mergeCells("A{$row}:F{$row}");
    $sheet->setCellValue("A{$row}", strtoupper($year));
    $sheet->getStyle("A{$row}:F{$row}")->applyFromArray($yearHeaderStyle);
    $sheet->getRowDimension($row)->setRowHeight(22);
    $row++;
    
    foreach ($semesters as $sem => $subjects) {
        // Semester header
        $sheet->mergeCells("A{$row}:F{$row}");
        $sheet->setCellValue("A{$row}", "  $sem");
        $sheet->getStyle("A{$row}:F{$row}")->applyFromArray($semHeaderStyle);
        $sheet->getRowDimension($row)->setRowHeight(20);
        $row++;
        
        $semLec = 0;
        $semLab = 0;
        $semUnits = 0;
        
        foreach ($subjects as $s) {
            $sheet->setCellValue("A{$row}", $num);
            $sheet->setCellValue("B{$row}", $s['code']);
            $sheet->setCellValue("C{$row}", $s['name']);
            $sheet->setCellValue("D{$row}", $s['lec']);
            $sheet->setCellValue("E{$row}", $s['lab'] ?: '');
            $sheet->setCellValue("F{$row}", $s['units']);
            $sheet->getStyle("A{$row}:F{$row}")->applyFromArray($dataStyle);
            $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("D{$row}:F{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            
            $semLec += $s['lec'];
            $semLab += $s['lab'];
            $semUnits += $s['units'];
            $num++;
            $row++;
        }
        
        // Semester total
        $sheet->mergeCells("A{$row}:C{$row}");
        $sheet->setCellValue("A{$row}", "  Subtotal — $sem");
        $sheet->setCellValue("D{$row}", $semLec);
        $sheet->setCellValue("E{$row}", $semLab);
        $sheet->setCellValue("F{$row}", $semUnits);
        $sheet->getStyle("A{$row}:F{$row}")->applyFromArray($totalStyle);
        $sheet->getStyle("D{$row}:F{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row++;
        
        $grandTotalLec += $semLec;
        $grandTotalLab += $semLab;
        $grandTotalUnits += $semUnits;
    }
    
    $row++; // blank row between years
}

// Grand total
$sheet->mergeCells("A{$row}:C{$row}");
$sheet->setCellValue("A{$row}", 'GRAND TOTAL');
$sheet->setCellValue("D{$row}", $grandTotalLec);
$sheet->setCellValue("E{$row}", $grandTotalLab);
$sheet->setCellValue("F{$row}", $grandTotalUnits);
$sheet->getStyle("A{$row}:F{$row}")->applyFromArray($headerStyle);

// ── Save Excel ───
$outputPath = dirname(__DIR__) . '/var/BSIT_Curriculum_2026.xlsx';
$writer = new Xlsx($spreadsheet);
$writer->save($outputPath);

echo "  Excel saved to: $outputPath\n";
echo "\n=== DONE ===\n";
echo "  $inserted subjects inserted into DB and linked to BSIT Curriculum.\n";
echo "  Excel file: var/BSIT_Curriculum_2026.xlsx\n";
