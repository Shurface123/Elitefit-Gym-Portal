<?php
session_start();
// require_once '../config/database.php';
require_once __DIR__ . '/../auth_middleware.php';
require_once 'trainer-theme-helper.php';

// Check if user is logged in and is a trainer
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'trainer') {
    header('Location: ../Public/login.php');
    exit();
}

$userId = $_SESSION['user_id'];
$userName = $_SESSION['name'];

require_once __DIR__ . '/../db_connect.php';
$conn = connectDB();

require_once 'trainer-theme-helper.php';
$theme = getThemePreference($conn, $userId);

// Handle PDF generation
if (isset($_GET['generate_pdf']) && isset($_GET['member_id'])) {
    require_once __DIR__ . '/../vendor/autoload.php'; // Assuming you have TCPDF or similar
    
    $member_id = $_GET['member_id'];
    $start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-3 months'));
    $end_date = $_GET['end_date'] ?? date('Y-m-d');
    
    generateAssessmentPDF($conn, $member_id, $start_date, $end_date, $userId);
    exit();
}

function generateAssessmentPDF($conn, $member_id, $start_date, $end_date, $userId) {
    // Get member info
    $member_query = "SELECT * FROM users WHERE id = ? AND trainer_id = ?";
    $member_stmt = $conn->prepare($member_query);
    $member_stmt->bind_param("ii", $member_id, $userId);
    $member_stmt->execute();
    $member = $member_stmt->get_result()->fetch_assoc();
    
    if (!$member) {
        die('Member not found or access denied');
    }
    
    // Get assessments
    $assessments_query = "SELECT a.*, at.name as assessment_name, at.category, at.fields
                         FROM assessments a
                         JOIN assessment_types at ON a.assessment_type_id = at.id
                         WHERE a.member_id = ? AND a.assessment_date BETWEEN ? AND ?
                         ORDER BY a.assessment_date ASC";
    $assessments_stmt = $conn->prepare($assessments_query);
    $assessments_stmt->bind_param("iss", $member_id, $start_date, $end_date);
    $assessments_stmt->execute();
    $assessments = $assessments_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Create PDF using TCPDF or similar library
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator('EliteFit Gym');
    $pdf->SetAuthor('EliteFit Trainer');
    $pdf->SetTitle('Assessment Report - ' . $member['first_name'] . ' ' . $member['last_name']);
    
    // Add a page
    $pdf->AddPage();
    
    // Set font
    $pdf->SetFont('helvetica', '', 12);
    
    // Header
    $pdf->Cell(0, 15, 'ELITEFIT GYM - ASSESSMENT REPORT', 0, 1, 'C');
    $pdf->Ln(5);
    
    // Member information
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, 'Member Information', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 8, 'Name: ' . $member['first_name'] . ' ' . $member['last_name'], 0, 1, 'L');
    $pdf->Cell(0, 8, 'Email: ' . $member['email'], 0, 1, 'L');
    $pdf->Cell(0, 8, 'Phone: ' . ($member['phone'] ?? 'N/A'), 0, 1, 'L');
    $pdf->Cell(0, 8, 'Report Period: ' . date('M j, Y', strtotime($start_date)) . ' - ' . date('M j, Y', strtotime($end_date)), 0, 1, 'L');
    $pdf->Ln(10);
    
    // Assessment summary
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, 'Assessment Summary', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 8, 'Total Assessments: ' . count($assessments), 0, 1, 'L');
    $pdf->Ln(5);
    
    // Assessment details
    foreach ($assessments as $assessment) {
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 10, $assessment['assessment_name'] . ' - ' . date('M j, Y', strtotime($assessment['assessment_date'])), 0, 1, 'L');
        
        $pdf->SetFont('helvetica', '', 10);
        $results = json_decode($assessment['results'], true);
        
        if ($results) {
            foreach ($results as $key => $value) {
                $pdf->Cell(0, 6, ucfirst(str_replace('_', ' ', $key)) . ': ' . $value, 0, 1, 'L');
            }
        }
        
        if ($assessment['notes']) {
            $pdf->Cell(0, 6, 'Notes: ' . $assessment['notes'], 0, 1, 'L');
        }
        
        $pdf->Ln(5);
    }
    
    // Output PDF
    $filename = 'assessment_report_' . $member['first_name'] . '_' . $member['last_name'] . '_' . date('Y-m-d') . '.pdf';
    $pdf->Output($filename, 'D');
}
?>
