<?php
// Ensure config and DB connection functions are loaded
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db_connect.php';

// --- DATABASE AND EXAM LOADING LOGIC ---
$examData = null;
$examError = null;
$module_id = isset($_GET['module_id']) ? (int)$_GET['module_id'] : 0;

// --- NEW: Get excluded question IDs from URL ---
$exclude_ids = [];
if (isset($_GET['exclude_ids'])) {
    // Sanitize the input by converting each part to an integer
    $exclude_ids = array_map('intval', explode(',', $_GET['exclude_ids']));
}


if ($module_id > 0) {
    // Get the database connection using the centralized function
    $study_plan_conn = getDBConnection();

    // Fetch the file path for the selected module
    $stmt = $study_plan_conn->prepare("SELECT file_path FROM study_modules WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $module_id);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $filePath =  $row['file_path'];

                if (!empty($filePath) && file_exists($filePath) && is_readable($filePath)) {
                    $jsonContent = file_get_contents($filePath);
                    $examData = json_decode($jsonContent, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $examError = "Invalid JSON in exam file: " . json_last_error_msg();
                    }

                    // --- NEW: Filter out excluded questions ---
                    if (!empty($exclude_ids) && isset($examData['questions']) && is_array($examData['questions'])) {
                        $original_count = count($examData['questions']);
                        $examData['questions'] = array_filter($examData['questions'], function($question) use ($exclude_ids) {
                            // Keep question if its ID is NOT in the exclude list
                            return !isset($question['id']) || !in_array($question['id'], $exclude_ids, true);
                        });
                        // Re-index the array to prevent JSON issues
                        $examData['questions'] = array_values($examData['questions']);
                    }
                    // --- End of new filtering logic ---

                } else {
                    $examError = "Exam file not found or is not readable. Path checked: " . realpath(dirname(__FILE__)) . '/' . $filePath;
                }
            } else {
                $examError = "Module not found in the database.";
            }
        } else {
            $examError = "Failed to execute database query.";
        }
        $stmt->close();
    } else {
        $examError = "Failed to prepare database query.";
    }
    // Close the connection
    $study_plan_conn->close();
}

// --- MODULE LIST LOADING ---
$modules = [];
// Get the database connection for the module list
$study_plan_conn_list = getDBConnection();
// Fetch file_path to count questions from JSON files
$sql = "SELECT id, module_code, module_name, file_path FROM study_modules sm ORDER BY module_name ASC";
$result = $study_plan_conn_list->query($sql);

// Check if the query was successful before fetching results
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $question_count = 0;
        $filePath = $row['file_path'];

        // Check if the file exists and is readable
        if (!empty($filePath) && file_exists($filePath) && is_readable($filePath)) {
            $jsonContent = file_get_contents($filePath);
            $examDataForCount = json_decode($jsonContent, true);
            // Ensure JSON is valid and 'questions' key exists
            if (json_last_error() === JSON_ERROR_NONE && isset($examDataForCount['questions']) && is_array($examDataForCount['questions'])) {
                $question_count = count($examDataForCount['questions']);
            }
        }
        // Add the question count to the module data
        $row['question_count'] = $question_count;
        $modules[] = $row;
    }
}
// Close the connection
$study_plan_conn_list->close();

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ExamPrep - Study Plans</title>
    <link rel="preconnect" href="https://fonts.gstatic.com/" crossorigin>
    <link rel="stylesheet" as="style" onload="this.rel='stylesheet'"
        href="https://fonts.googleapis.com/css2?display=swap&family=Inter%3Awght%40400%3B500%3B600%3B700%3B800%3B900&family=Noto+Sans%3Awght%40400%3B500%3B600%3B700%3B800%3B900">
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <style>
        /* --- Base Animations (Largely Unchanged) --- */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .fade-in-up {
            animation: fadeInUp 0.7s ease-out forwards;
        }
        
        /* === MODERN GREEN & WHITE THEME === */

        /* --- Main Layout & Background --- */
        body {
            font-family: Inter, "Noto Sans", sans-serif;
        }
        .hero-gradient {
            background-color: #f8f9fa;
            position: relative;
            overflow: hidden;
        }

        .cyber-grid {
            background-image:
                linear-gradient(rgba(0, 0, 0, 0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0, 0, 0, 0.03) 1px, transparent 1px);
            background-size: 60px 60px;
        }

        .glass-effect {
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            background: rgba(255, 255, 255, 0.8);
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        /* --- Typography --- */
        .text-gradient {
            background: none;
            -webkit-background-clip: initial;
            -webkit-text-fill-color: initial;
            color: #1a202c;
        }
        
        /* --- Cards & Containers --- */
        .modern-card,
        .holographic-card {
            background: #ffffff;
            backdrop-filter: none;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.07), 0 2px 4px -1px rgba(0, 0, 0, 0.04);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .modern-card:hover,
        .holographic-card:hover {
            transform: translateY(-5px);
            border-color: #22c55e;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.08), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
        
        .course-card-item>span {
            background: #e6f9f0 !important;
            color: #166534 !important;
            font-weight: 600 !important;
        }

        /* --- Buttons & Interactive Elements --- */
        .modern-button {
            background: #22c55e;
            border: none;
            color: white;
            font-weight: 600;
            transition: all 0.2s ease;
        }

        .modern-button:hover {
            background: #16a34a;
            transform: translateY(-2px);
            box-shadow: 0 7px 14px rgba(34, 197, 94, 0.2), 0 3px 6px rgba(0, 0, 0, 0.08);
        }

        /* --- Study Plan Specific Styles --- */
        .config-section, .question-section, .results-section, #automaticExamSelectionSection {
            background: #ffffff;
            border-radius: 0.75rem;
            /* Padding moved to Tailwind classes for responsiveness */
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.07), 0 2px 4px -1px rgba(0,0,0,0.04);
            margin-top: 2rem;
        }
        
        .config-item label, .lesson-input-item label {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 0.5rem;
        }
        
        input[type="number"], input[type="range"] {
            padding: 0.75rem 1rem;
            border: 1px solid #cbd5e1;
            border-radius: 0.5rem;
            font-size: 1rem;
            transition: border-color 0.2s, box-shadow 0.2s;
            background: #f8f9fa;
        }
        
        input[type="number"]:focus, input[type="range"]:focus {
            border-color: #22c55e;
            box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.2);
            outline: none;
        }

        .start-btn-container {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin-top: 1.5rem;
            flex-wrap: wrap;
        }

        .btn, .start-btn {
            background: #22c55e;
            color: #fff;
            border: none;
            padding: 0.75rem 2rem;
            border-radius: 0.5rem;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .btn:hover, .start-btn:hover {
            background: #16a34a;
        }
        
        .start-btn.random-btn { background: #3b82f6; }
        .start-btn.random-btn:hover { background: #2563eb; }
        .start-btn.auto-btn { background: #f59e0b; }
        .start-btn.auto-btn:hover { background: #d97706; }
        
        .btn-secondary { background: #e2e8f0; color: #1a202c; }
        .btn-secondary:hover { background: #cbd5e1; }
        
        .btn:disabled, .start-btn:disabled {
            background: #e2e8f0;
            color: #94a3b8;
            cursor: not-allowed;
        }

        .question-header {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
        }
        .progress-bar { background: #e2e8f0; height: 8px; border-radius: 4px; overflow: hidden; }
        .progress-fill { background: #22c55e; height: 100%; transition: width 0.3s; }
        
        .option {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 0.5rem;
            border: 2px solid transparent;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .option:hover { border-color: #99d6b3; }
        .option.selected { border-color: #22c55e; background: #e6f9f0; font-weight: bold; }
        .option.correct { background: #d1fae5; border-color: #10b981; }
        .option.incorrect { background: #feF3c7; border-color: #f59e0b; }
        
        .explanation {
            display: none;
            background: #f0f9ff;
            padding: 1rem;
            border-radius: 0.5rem;
            margin-top: 1.5rem;
            border-left: 4px solid #3b82f6;
        }
        
        .modal-overlay {
            display: none; 
            position: fixed; 
            z-index: 1000; 
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto; 
            background-color: rgba(0,0,0,0.6);
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background-color: #fff;
            padding: 2rem;
            border-radius: 0.75rem;
            text-align: center;
            max-width: 450px;
            width: 90%;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
    </style>
</head>

<body>

    <div
        class="relative flex size-full min-h-screen flex-col hero-gradient group/design-root overflow-x-hidden cyber-grid">
        <div class="layout-container flex h-full grow flex-col relative z-10">
            <header
                class="flex items-center justify-between whitespace-nowrap px-4 sm:px-10 py-3 glass-effect sticky top-0 z-50 shadow-sm">
                <a href="index.php"
                    class="flex items-center gap-3 text-slate-900 hover:scale-105 transition-transform duration-300">
                    <div class="size-8 text-white modern-button rounded-lg p-1.5 shadow-md">
                        <svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path
                                d="M36.7273 44C33.9891 44 31.6043 39.8386 30.3636 33.69C29.123 39.8386 26.7382 44 24 44C21.2618 44 18.877 39.8386 17.6364 33.69C16.3957 39.8386 14.0109 44 11.2727 44C7.25611 44 4 35.0457 4 24C4 12.9543 7.25611 4 11.2727 4C14.0109 4 16.3957 8.16144 17.6364 14.31C18.877 8.16144 21.2618 4 24 4C26.7382 4 29.123 8.16144 30.3636 14.31C31.6043 8.16144 33.9891 4 36.7273 4C40.7439 4 44 12.9543 44 24C44 35.0457 40.7439 44 36.7273 44Z"
                                fill="currentColor"></path>
                        </svg>
                    </div>
                    <h2 class="text-gray-800 text-xl font-bold tracking-tight">KURU EXAM</h2>
                </a>
                
                <nav class="hidden sm:flex items-center gap-8">
                    <a class="text-gray-600 hover:text-green-600 text-sm font-medium transition-all duration-200 relative group"
                        href="index.php">
                        My Exams
                        <span
                            class="absolute -bottom-1 left-0 w-0 h-0.5 bg-green-500 transition-all duration-300 group-hover:w-full"></span>
                    </a>
                    <a class="text-gray-600 hover:text-green-600 text-sm font-medium transition-all duration-200 relative group"
                        href="index.php?page=study_plans">
                        Study Plans
                        <span
                            class="absolute -bottom-1 left-0 w-full h-0.5 bg-green-500"></span>
                    </a>
                    <a class="text-gray-600 hover:text-green-600 text-sm font-medium transition-all duration-200 relative group"
                        href="#">
                        KuruMovies
                        <span
                            class="absolute -bottom-1 left-0 w-0 h-0.5 bg-green-500 transition-all duration-300 group-hover:w-full"></span>
                    </a>
                </nav>

                <div class="sm:hidden flex items-center">
                    <button id="menu-btn" class="text-gray-800 hover:text-green-600 focus:outline-none">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 12h16m-7 6h7" />
                        </svg>
                    </button>
                </div>
            </header>

            <div id="mobile-menu" class="hidden sm:hidden bg-white shadow-lg">
                <a href="index.php" class="block py-2 px-4 text-sm text-gray-700 hover:bg-green-50">My Exams</a>
                <a href="index.php?page=study_plans" class="block py-2 px-4 text-sm text-gray-700 bg-green-100">Study Plans</a>
                <a href="#" class="block py-2 px-4 text-sm text-gray-700 hover:bg-green-50">KuruMovies</a>
            </div>

            <main id="layout-content-container" class="flex justify-center flex-1 px-4 sm:px-10 py-8 sm:py-12">
                <div class="layout-content-container flex flex-col max-w-[960px] flex-1">
                    
                    <div id="main-content-area">
                        <div class="w-full mb-8 sm:mb-12 fade-in-up text-center">
                            <h1 class="text-gradient text-4xl sm:text-6xl font-extrabold tracking-tight mb-4">Study Plans</h1>
                            <p class="text-gray-600 text-base sm:text-lg max-w-2xl mx-auto">
                                Select a module to begin a focused study session. Use our tools to create manual, random, or AI-driven mini-exams.
                            </p>
                        </div>
                    </div>
                    
                    <form action="" method="GET" id="moduleForm" style="display: none;">
                        <input type="hidden" name="page" value="study_plans">
                        <input type="hidden" name="module_id" id="selected_module_id">
                        <input type="hidden" name="exclude_ids" id="exclude_ids_input">
                    </form>

                    <div id="selectionSection" class="selection-section">
                         <div class="grid grid-cols-[repeat(auto-fit,minmax(280px,1fr))] gap-4 md:gap-6">
                            <?php if (empty($modules)): ?>
                                <p class="text-gray-500 col-span-full text-center py-10 text-lg">No study modules found.</p>
                            <?php else: ?>
                                <?php foreach ($modules as $module) : ?>
                                    <div class="course-card-item holographic-card flex flex-col gap-4 rounded-xl p-5 sm:p-6 text-left cursor-pointer" onclick="selectModuleAndSubmit(<?php echo $module['id']; ?>)">
                                        <div class="text-white modern-button p-3 rounded-lg w-fit shadow-md">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="32px" height="32px" fill="currentColor" viewBox="0 0 256 256"><path d="M234.82,82.23l-80-64a16,16,0,0,0-17.64,0l-80,64A16,16,0,0,0,48,96V208a16,16,0,0,0,16,16H192a16,16,0,0,0,16-16V96A16,16,0,0,0,234.82,82.23ZM128,34.51,194.3,88H61.7ZM192,208H64V98.78l64,51.2,64-51.2V208Z"></path></svg>
                                        </div>
                                        <div class="flex-grow">
                                            <h3 class="text-gray-800 text-lg font-bold leading-tight mb-1"><?php echo htmlspecialchars($module['module_name']); ?></h3>
                                            <p class="text-sm text-gray-500 font-medium"><?php echo htmlspecialchars($module['module_code']); ?></p>
                                        </div>
                                         <span class="text-sm font-bold py-1 px-3 rounded-md w-fit"><?php echo $module['question_count']; ?> Questions</span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                         </div>
                    </div>

                    <div class="config-section p-6 md:p-8" id="configSection" style="display:none;">
                        <h3 class="text-xl md:text-2xl font-bold text-gray-800 mb-6">⚙️ Exam Configuration</h3>
                        <div class="grid md:grid-cols-2 gap-6">
                             <div class="config-item">
                                <label for="miniExamSize">Automatic Mode: Mini-Exam Size</label>
                                <input type="number" id="miniExamSize" min="1" placeholder="e.g., 35" class="w-full mt-2"/>
                            </div>
                            <div class="config-item">
                                <label for="totalExamQuestions">Random Exam Percentage</label>
                                 <div class="flex items-center gap-4 mt-2">
                                    <input type="range" id="totalExamQuestions" min="1" max="100" value="100" class="w-full" />
                                    <span class="font-bold text-green-600" id="totalExamQuestionsValue">100%</span>
                                </div>
                            </div>
                        </div>
                        <hr class="my-8" />
                        <h4 class="text-lg md:text-xl font-bold text-gray-800">Build a Manual Exam</h4>
                        <p class="text-gray-500 mb-6 text-sm md:text-base">
                            Select a percentage of questions to draw from each lesson.
                        </p>
                        <div id="lessonDistributionContainer" class="grid grid-cols-[repeat(auto-fit,minmax(220px,1fr))] gap-6"></div>
                        <div id="distributionTotalDisplay" class="text-center font-bold text-base md:text-lg p-3 mt-4 rounded-lg bg-gray-100 text-green-700"></div>
                        <div class="start-btn-container">
                            <button id="startManualExamBtn" class="start-btn" onclick="startManualExam()">
                                ⚙️ Start Manual
                            </button>
                            <button id="randomExamBtn" class="start-btn random-btn" onclick="startRandomExam()">
                                🎲 Start Random
                            </button>
                            <button id="automaticExamBtn" class="start-btn auto-btn" onclick="prepareAutomaticExams()">
                                🤖 Start Auto
                            </button>
                        </div>
                    </div>
                    
                    <div id="automaticExamSelectionSection" class="p-6 md:p-8" style="display:none;">
                        <h3 class="text-xl md:text-2xl font-bold text-gray-800 mb-2">📜 Your Mini-Exams</h3>
                        <p class="text-gray-500 mb-6 text-sm md:text-base">Select an exam to begin. Your progress will be saved.</p>
                        <div id="miniExamListContainer" class="grid grid-cols-[repeat(auto-fit,minmax(250px,1fr))] gap-4"></div>
                         <div class="start-btn-container mt-8">
                             <button class="btn btn-secondary" onclick="resetExam()">
                                Back to Main Menu
                            </button>
                        </div>
                    </div>


                    <div class="question-section p-4 sm:p-6" id="questionSection" style="display:none;">
                        <div class="question-header">
                            <div class="progress-bar">
                                <div class="progress-fill" id="progressFill"></div>
                            </div>
                            <div class="flex justify-between items-center mt-3 text-sm">
                                <span id="questionProgress" class="font-bold text-green-600">Question 1 of N</span>
                                <span id="examTitle" class="font-bold text-gray-700 hidden sm:block">Exam Title</span>
                                <button class="text-red-600 hover:text-red-800 font-semibold" onclick="showStudyPlanExitModal()">
                                    Stop Exam
                                </button>
                            </div>
                        </div>
                        <div class="question-card mt-6">
                            <div class="question-text text-lg md:text-xl font-semibold mb-6 text-gray-900" id="questionText"></div>
                            <div class="options flex flex-col gap-3" id="optionsContainer"></div>
                            <div class="explanation" id="explanation">
                                <h4 class="font-bold text-blue-600 mb-2">💡 Explanation:</h4>
                                <div id="explanationText" class="text-sm md:text-base"></div>
                            </div>
                        </div>
                        <div class="control-buttons flex flex-col-reverse sm:flex-row justify-between items-center mt-8 gap-4">
                            <button class="btn btn-secondary w-full sm:w-auto" onclick="previousQuestion()">
                                ← Previous
                            </button>
                            <div class="flex gap-3 w-full sm:w-auto">
                                <button class="btn btn-secondary bg-yellow-400 text-yellow-900 hover:bg-yellow-500 flex-1 justify-center" id="toggleAnswerBtn" onclick="toggleAnswer()">
                                    👁️<span class="ml-2 hidden sm:inline">Show Answer</span>
                                </button>
                                <button class="btn flex-1 justify-center" id="nextBtn" onclick="nextQuestion()">
                                    <span class="mr-2 hidden sm:inline">Next</span> →
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="results-section p-6 md:p-8" id="resultsSection" style="display:none;">
                        <div class="score-display bg-green-500 text-white p-6 sm:p-8 rounded-lg mb-8 text-center">
                            <h2 id="finalScore" class="text-4xl sm:text-5xl font-bold">0%</h2>
                            <p class="mt-1">Your Final Score</p>
                            <p id="scoreDetails" class="text-sm opacity-80">0 out of 0 questions correct</p>
                        </div>
                        <div class="lesson-stats grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-4 md:gap-6" id="lessonStats"></div>
                        
                        <div id="incorrectReviewContainer" class="mt-8"></div>
                        
                        <div class="start-btn-container mt-8">
                            <button class="btn" onclick="resetExam()">
                                🔄 New Exam
                            </button>
                            <button id="continuePracticeBtn" class="btn bg-blue-500 hover:bg-blue-600" onclick="continuePractice()">
                                📚 Continue
                            </button>
                            <button id="nextAutomaticExamBtn" class="btn bg-yellow-400 text-yellow-900 hover:bg-yellow-500" style="display:none;" onclick="">
                               Next Mini-Exam
                            </button>
                            <button id="retakeIncorrectBtn" class="btn btn-secondary" onclick="retakeIncorrectStudyMode()" style="display:none;">
                                🔁 Retake Incorrect
                            </button>
                        </div>
                    </div>

                </div>
            </main>

            <footer class="flex justify-center border-t border-solid border-gray-200 bg-white mt-auto">
                <div class="flex max-w-[960px] flex-1 flex-col px-8 py-8 text-center">
                    <div class="flex items-center justify-center gap-3 mb-4">
                        <div class="size-8 text-white modern-button rounded-lg p-1.5 shadow-md">
                            <svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path
                                    d="M36.7273 44C33.9891 44 31.6043 39.8386 30.3636 33.69C29.123 39.8386 26.7382 44 24 44C21.2618 44 18.877 39.8386 17.6364 33.69C16.3957 39.8386 14.0109 44 11.2727 44C7.25611 44 4 35.0457 4 24C4 12.9543 7.25611 4 11.2727 4C14.0109 4 16.3957 8.16144 17.6364 14.31C18.877 8.16144 21.2618 4 24 4C26.7382 4 29.123 8.16144 30.3636 14.31C31.6043 8.16144 33.9891 4 36.7273 4C40.7439 4 44 12.9543 44 24C44 35.0457 40.7439 44 36.7273 44Z"
                                    fill="currentColor"></path>
                            </svg>
                        </div>
                        <h3 class="text-gray-800 text-xl font-bold">KURU PLC</h3>
                    </div>
                    <p class="text-gray-600 text-base leading-relaxed mb-4"></p>
                    <p class="text-gray-500 text-sm font-medium">© 2025 . All rights reserved.</p>
                </div>
            </footer>
        </div>
    </div>

    <div id="studyPlanExitModal" class="modal-overlay">
        <div class="modal-content">
            <h3 class="text-xl sm:text-2xl font-bold text-gray-800 mb-4">Confirm Exit</h3>
            <p class="text-gray-600 mb-8">Are you sure you want to stop the exam? Your progress will be lost.</p>
            <div class="flex justify-center gap-4">
                <button id="cancelExitBtn" class="btn btn-secondary">Stay</button>
                <button id="confirmExitBtn" class="btn bg-red-500 hover:bg-red-600">Stop Exam</button>
            </div>
        </div>
    </div>

    <script src="assets/scripts.js"></script>
    <script>
        // Use the examData and examError from PHP
        let examData = <?php echo json_encode($examData); ?>;
        const examError = <?php echo json_encode($examError); ?>;
        const module_id = <?php echo $module_id; ?>;
        
        const urlParams = new URLSearchParams(window.location.search);
        const initialExcludeIdsStr = urlParams.get('exclude_ids') || '';
        let allExcludedIds = initialExcludeIdsStr ? initialExcludeIdsStr.split(',').map(Number) : [];

        let currentQuestions = [];
        let currentQuestionIndex = 0;
        let userAnswers = [];
        let isAnswerVisible = false;
        let incorrectQuestions = [];
        let lessonQuestionCounts = {};
        
        // --- NEW: Automatic Mode Variables ---
        let currentMode = null; // 'random', 'manual', 'automatic'
        let miniExams = [];
        let currentMiniExamIndex = -1;

        // Run this on page load
        document.addEventListener('DOMContentLoaded', function() {
            if (examError) {
                alert('Error: ' + examError);
            }
            if (module_id > 0) { // If a module is selected
                document.getElementById('selectionSection').style.display = 'none';
                document.getElementById('main-content-area').style.display = 'none';
                 if (examData && examData.questions && examData.questions.length > 0) {
                    setupExamConfiguration();
                } else {
                    const contentDiv = document.querySelector('.layout-content-container.flex-col');
                    contentDiv.innerHTML = `
                        <div class="text-center p-8 bg-white rounded-lg shadow-md">
                            <h2 class="text-3xl font-bold text-green-600 mb-4">Congratulations!</h2>
                            <p class="text-gray-700 text-lg mb-6">You have completed all the questions for this module.</p>
                            <button class="btn" onclick="window.location.href='index.php?page=study_plans'">
                                Back to Module Selection
                            </button>
                        </div>
                    `;
                }
            } else { // No module selected, show selection screen
                document.getElementById('selectionSection').style.display = 'block';
                document.getElementById('configSection').style.display = 'none';
            }
            
            const exitModal = document.getElementById('studyPlanExitModal');
            document.getElementById('confirmExitBtn').addEventListener('click', resetExam);
            document.getElementById('cancelExitBtn').addEventListener('click', hideStudyPlanExitModal);

            exitModal.addEventListener('click', function(event) {
                if (event.target === exitModal) {
                    hideStudyPlanExitModal();
                }
            });

        });
        
        function showStudyPlanExitModal() {
            document.getElementById('studyPlanExitModal').style.display = 'flex';
        }

        function hideStudyPlanExitModal() {
            document.getElementById('studyPlanExitModal').style.display = 'none';
        }

        function selectModuleAndSubmit(selectedModuleId) {
            document.getElementById('selected_module_id').value = selectedModuleId;
            document.getElementById('moduleForm').submit();
        }

        function continuePractice() {
            const form = document.getElementById('moduleForm');
            const moduleIdInput = document.getElementById('selected_module_id');
            const excludeIdsInput = document.getElementById('exclude_ids_input');

            moduleIdInput.value = module_id;
            excludeIdsInput.value = allExcludedIds.join(',');

            form.submit();
        }


        function setupExamConfiguration() {
            document.getElementById("selectionSection").style.display = "none";
            document.getElementById("configSection").style.display = "block";

            lessonQuestionCounts = {};
            if (!examData || !examData.questions || examData.questions.length === 0) {
                return;
            }

            examData.questions.forEach((q) => {
                const lesson = q.lesson || 'General';
                lessonQuestionCounts[lesson] = (lessonQuestionCounts[lesson] || 0) + 1;
            });
            
            document.getElementById("miniExamSize").max = examData.questions.length;

            const totalExamQuestionsInput = document.getElementById("totalExamQuestions");
            const totalExamQuestionsValue = document.getElementById("totalExamQuestionsValue");
            totalExamQuestionsInput.addEventListener('input', () => {
                totalExamQuestionsValue.textContent = `${totalExamQuestionsInput.value}%`;
            });


            const lessons = Object.keys(lessonQuestionCounts).sort();
            const distributionContainer = document.getElementById("lessonDistributionContainer");
            distributionContainer.innerHTML = "";

            lessons.forEach((lesson) => {
                const available = lessonQuestionCounts[lesson];
                const item = document.createElement("div");
                item.className = "lesson-input-item bg-gray-50 p-4 rounded-lg";
                item.innerHTML = `
                    <label for="dist-lesson-${lesson}" class="font-semibold text-gray-700">Lesson ${lesson} <span class="text-gray-500 font-normal">(${available} avail.)</span></label>
                    <div class="flex items-center gap-4 mt-2">
                        <input type="range" id="dist-lesson-${lesson}" data-lesson="${lesson}" min="0" max="100" value="0" class="w-full">
                        <span class="range-value font-bold text-green-600" id="dist-lesson-${lesson}-value">0%</span>
                    </div>
                `;
                distributionContainer.appendChild(item);
                
                const slider = item.querySelector('input[type="range"]');
                const display = item.querySelector('.range-value');
                slider.addEventListener('input', () => {
                    display.textContent = `${slider.value}%`;
                    updateManualExamTotal();
                });
            });
            
            updateManualExamTotal();
        }

        function updateManualExamTotal() {
            const startBtn = document.getElementById("startManualExamBtn");
            const totalDisplay = document.getElementById("distributionTotalDisplay");
            let currentTotal = 0;
            
            document.querySelectorAll('#lessonDistributionContainer input[type="range"]').forEach((input) => {
                const percentage = parseInt(input.value) || 0;
                const lesson = input.dataset.lesson;
                const availableForLesson = lessonQuestionCounts[lesson] || 0;
                currentTotal += Math.round((percentage / 100) * availableForLesson);
            });

            totalDisplay.textContent = `Total Manual Questions: ${currentTotal}`;
            startBtn.textContent = `⚙️ Start Manual Exam (${currentTotal} Questions)`;
            startBtn.disabled = !(currentTotal > 0);
        }

        function startRandomExam() {
            currentMode = 'random';
            const percentage = parseInt(document.getElementById("totalExamQuestions").value) || 0;
             if (percentage <= 0) {
                alert("Please set a positive percentage of questions.");
                return;
            }
            const allQuestions = examData.questions;
            const totalQuestionsToTake = Math.round((percentage / 100) * allQuestions.length);
            
            if (totalQuestionsToTake === 0 && percentage > 0) {
                alert("The selected percentage results in 0 questions. Please choose a higher percentage.");
                return;
            }
            if (totalQuestionsToTake > allQuestions.length) {
                alert(`Requested ${totalQuestionsToTake}, but only ${allQuestions.length} are available.`);
                return;
            }
            currentQuestions = shuffleArray(allQuestions).slice(0, totalQuestionsToTake);
            beginExam();
        }

        function startManualExam() {
            currentMode = 'manual';
            let questionsToBuild = [];
            document.querySelectorAll('#lessonDistributionContainer input[type="range"]').forEach((input) => {
                const percentage = parseInt(input.value) || 0;
                if (percentage > 0) {
                    const lesson = input.dataset.lesson;
                    const questionsFromLesson = examData.questions.filter((q) => (q.lesson || 'General') === lesson);
                    const numToTake = Math.round((percentage / 100) * questionsFromLesson.length);
                    if(numToTake > 0){
                       questionsToBuild.push(...shuffleArray(questionsFromLesson).slice(0, numToTake));
                    }
                }
            });
            if (questionsToBuild.length === 0) {
                alert("Your selection resulted in 0 questions. Please increase the percentage for one or more lessons.");
                return;
            }
            currentQuestions = shuffleArray(questionsToBuild);
            beginExam();
        }
        
        function prepareAutomaticExams() {
            currentMode = 'automatic';
            const size = parseInt(document.getElementById('miniExamSize').value);
            const allQuestions = examData.questions;

            if (!size || size <= 0) {
                alert('Please enter a valid size for the mini-exams.');
                return;
            }
            if (size > allQuestions.length) {
                alert(`Mini-exam size (${size}) cannot be larger than the total number of available questions (${allQuestions.length}).`);
                return;
            }

            const shuffledQuestions = shuffleArray(allQuestions);
            miniExams = [];
            for (let i = 0; i < shuffledQuestions.length; i += size) {
                miniExams.push(shuffledQuestions.slice(i, i + size));
            }

            renderAutomaticExamChoices();
            document.getElementById('configSection').style.display = 'none';
            document.getElementById('automaticExamSelectionSection').style.display = 'block';
        }

        function renderAutomaticExamChoices() {
            const container = document.getElementById('miniExamListContainer');
            container.innerHTML = '';
            miniExams.forEach((exam, index) => {
                const btn = document.createElement('button');
                btn.className = 'btn text-left justify-start w-full';
                btn.innerHTML = `Mini-Exam ${index + 1} <span class="ml-auto bg-gray-200 text-gray-700 text-xs font-bold px-2 py-1 rounded-full">${exam.length} Qs</span>`;
                btn.onclick = () => takeMiniExam(index);
                container.appendChild(btn);
            });
        }
        
        function takeMiniExam(index) {
            currentMiniExamIndex = index;
            currentQuestions = miniExams[index];
            document.getElementById('automaticExamSelectionSection').style.display = 'none';
            beginExam();
        }


        function beginExam() {
            if (currentQuestions.length === 0) {
                alert("Your configuration resulted in 0 questions.");
                return;
            }

            const newAnsweredIds = currentQuestions.map(q => q.id).filter(id => id !== undefined);
            allExcludedIds = [...new Set([...allExcludedIds, ...newAnsweredIds])];

            userAnswers = new Array(currentQuestions.length).fill(null);
            incorrectQuestions = [];
            currentQuestionIndex = 0;
            isAnswerVisible = false;
            document.getElementById("resultsSection").style.display = "none";
            document.getElementById("configSection").style.display = "none";
            document.getElementById("questionSection").style.display = "block";
            
            let title = examData.exam_title || "Exam";
            if (currentMode === 'automatic') {
                title = `Mini-Exam ${currentMiniExamIndex + 1}`;
            }
            document.getElementById("examTitle").textContent = title;
            displayQuestion();
        }

        function displayQuestion() {
            isAnswerVisible = false;
            const question = currentQuestions[currentQuestionIndex];
            const progress = ((currentQuestionIndex + 1) / currentQuestions.length) * 100;
            document.getElementById("progressFill").style.width = progress + "%";
            document.getElementById("questionProgress").textContent = `Question ${currentQuestionIndex + 1} of ${currentQuestions.length}`;
            document.getElementById("questionText").textContent = question.question;
            const optionsContainer = document.getElementById("optionsContainer");
            optionsContainer.innerHTML = "";

            const optionsArray = Object.entries(question.options);
            const shuffledOptions = shuffleArray(optionsArray);

            shuffledOptions.forEach(([key, value]) => {
                const option = document.createElement("div");
                option.className = "option";
                option.dataset.optionKey = key; // Add data attribute to track the original key
                option.textContent = value; // Set only the text, no letter prefix
                option.onclick = () => selectOption(key);
                if (userAnswers[currentQuestionIndex] === key) {
                    option.classList.add("selected");
                }
                optionsContainer.appendChild(option);
            });
            
            renderAnswerState();
            updateNavigationButtons();
        }

        function selectOption(optionKey) {
            if (isAnswerVisible) return;
            userAnswers[currentQuestionIndex] = optionKey;

            document.querySelectorAll(".option").forEach((opt) => {
                // Use the data attribute to check which option this is
                if (opt.dataset.optionKey === optionKey) {
                    opt.classList.add("selected");
                } else {
                    opt.classList.remove("selected");
                }
            });
            
            updateNavigationButtons();
        }

        function toggleAnswer() {
            isAnswerVisible = !isAnswerVisible;
            renderAnswerState();
        }

        function renderAnswerState() {
            const toggleBtn = document.getElementById("toggleAnswerBtn");
            const explanationDiv = document.getElementById("explanation");
            const explanationTextDiv = document.getElementById("explanationText");
            const options = document.querySelectorAll(".option");
            const question = currentQuestions[currentQuestionIndex];
            
            if (isAnswerVisible) {
                toggleBtn.innerHTML = "🙈<span class='ml-2 hidden sm:inline'>Hide Answer</span>";
                
                // --- MODIFICATION: Always show explanation with correct answer ---
                explanationDiv.style.display = "block";
                const correctAnswerText = question.options[question.correct_answer];
                let explanationContent = `<p class="font-semibold">Correct Answer: <span class="font-bold">${correctAnswerText}</span></p>`;

                if (question.explanation) {
                    explanationContent += `<div class="mt-2 pt-2 border-t border-blue-200">${question.explanation}</div>`;
                }
                explanationTextDiv.innerHTML = explanationContent;
                // --- END MODIFICATION ---

                const userAnswer = userAnswers[currentQuestionIndex];
                options.forEach((opt) => {
                    opt.style.pointerEvents = "none";
                    const optionKey = opt.dataset.optionKey;
                    if (optionKey === question.correct_answer) {
                        opt.classList.add("correct");
                    } else if (optionKey === userAnswer) {
                        opt.classList.add("incorrect");
                    }
                });

            } else {
                toggleBtn.innerHTML = "👁️<span class='ml-2 hidden sm:inline'>Show Answer</span>";
                explanationDiv.style.display = "none";
                options.forEach((opt) => {
                    opt.style.pointerEvents = "auto";
                    opt.classList.remove("correct", "incorrect");
                });
            }
        }

        function updateNavigationButtons() {
            const nextBtn = document.getElementById("nextBtn");
            document.querySelector(".control-buttons .btn-secondary").disabled = currentQuestionIndex === 0;
            if (currentQuestionIndex === currentQuestions.length - 1) {
                nextBtn.innerHTML = "🏆<span class='ml-2 hidden sm:inline'>Submit Exam</span>";
            } else {
                nextBtn.innerHTML = "<span class='mr-2 hidden sm:inline'>Next</span> →";
            }
            nextBtn.disabled = userAnswers[currentQuestionIndex] === null;
        }

        function nextQuestion() {
            if (userAnswers[currentQuestionIndex] === null) {
                alert("Please select an answer before proceeding.");
                return;
            }
            if (currentQuestionIndex < currentQuestions.length - 1) {
                currentQuestionIndex++;
                displayQuestion();
            } else {
                showResults();
            }
        }

        function previousQuestion() {
            if (currentQuestionIndex > 0) {
                currentQuestionIndex--;
                displayQuestion();
            }
        }

        function showResults() {
            document.getElementById("questionSection").style.display = "none";
            document.getElementById("resultsSection").style.display = "block";
            let correctAnswers = 0;
            const lessonStats = {};
            incorrectQuestions = []; 
            currentQuestions.forEach((question, index) => {
                const isCorrect = userAnswers[index] === question.correct_answer;
                if (isCorrect) {
                    correctAnswers++;
                } else {
                    incorrectQuestions.push({
                        question: question,
                        userAnswer: userAnswers[index]
                    });
                }
                const lesson = question.lesson || 'General';
                if (!lessonStats[lesson]) {
                    lessonStats[lesson] = {
                        correct: 0,
                        total: 0
                    };
                }
                lessonStats[lesson].total++;
                if (isCorrect) lessonStats[lesson].correct++;
            });
            const percentage = currentQuestions.length > 0 ? Math.round((correctAnswers / currentQuestions.length) * 100) : 0;
            document.getElementById("finalScore").textContent = percentage + "%";
            document.getElementById("scoreDetails").textContent = `${correctAnswers} out of ${currentQuestions.length} questions correct`;
            const lessonStatsContainer = document.getElementById("lessonStats");
            lessonStatsContainer.innerHTML = "";
            Object.entries(lessonStats).sort(([a], [b]) => a.localeCompare(b)).forEach(([lesson, stats]) => {
                const lessonPercentage = Math.round((stats.correct / stats.total) * 100);
                const card = document.createElement("div");
                card.className = `lesson-card bg-white p-4 rounded-lg shadow-sm border`;
                card.innerHTML = `<h4 class="font-bold text-gray-800">Lesson ${lesson}</h4><div class="text-2xl sm:text-3xl font-bold text-green-500 my-2">${lessonPercentage}%</div><p class="text-sm text-gray-600">${stats.correct}/${stats.total} correct</p>`;
                lessonStatsContainer.appendChild(card);
            });
            renderIncorrectReview();
            
            // --- Update button visibility based on mode ---
            const retakeBtn = document.getElementById("retakeIncorrectBtn");
            const continuePracticeBtn = document.getElementById('continuePracticeBtn');
            const nextAutoBtn = document.getElementById('nextAutomaticExamBtn');
            
            continuePracticeBtn.style.display = 'none';
            nextAutoBtn.style.display = 'none';
            retakeBtn.style.display = incorrectQuestions.length > 0 ? 'inline-block' : 'none';

            if (currentMode === 'automatic') {
                const completedButton = document.querySelector(`#miniExamListContainer .btn:nth-child(${currentMiniExamIndex + 1})`);
                if(completedButton) {
                    completedButton.classList.add('bg-green-200', 'text-green-800', 'line-through');
                    completedButton.disabled = true;
                }

                if (currentMiniExamIndex + 1 < miniExams.length) {
                    nextAutoBtn.style.display = 'inline-block';
                    nextAutoBtn.textContent = `Continue to Mini-Exam ${currentMiniExamIndex + 2}`;
                    nextAutoBtn.onclick = () => takeMiniExam(currentMiniExamIndex + 1);
                } else {
                     nextAutoBtn.style.display = 'inline-block';
                     nextAutoBtn.textContent = "All Exams Done! Back to List";
                     nextAutoBtn.onclick = backToAutomaticSelection;
                }
                const existingBackBtn = document.getElementById('backToAutoListBtn');
                if (!existingBackBtn) {
                    const backBtn = document.createElement('button');
                    backBtn.id = 'backToAutoListBtn';
                    backBtn.className = 'btn btn-secondary';
                    backBtn.textContent = 'Back to Mini-Exam List';
                    backBtn.onclick = backToAutomaticSelection;
                    nextAutoBtn.parentNode.insertBefore(backBtn, nextAutoBtn.nextSibling);
                }

            } else {
                 if (examData && examData.questions && examData.questions.length > 0) {
                     continuePracticeBtn.style.display = 'inline-block';
                 }
            }
        }
        
        function backToAutomaticSelection() {
            document.getElementById('resultsSection').style.display = 'none';
            document.getElementById('automaticExamSelectionSection').style.display = 'block';
        }

        
        function renderIncorrectReview() {
            const container = document.getElementById("incorrectReviewContainer");
            container.innerHTML = ''; // Clear previous review
            if (incorrectQuestions.length === 0) {
                return;
            }

            const summary = document.createElement('div');
            summary.className = 'review-summary bg-gray-100 p-4 rounded-lg cursor-pointer hover:bg-gray-200 transition';
            summary.innerHTML = `<h3 class="font-bold text-gray-800">Review ${incorrectQuestions.length} Incorrect Answers <span class="text-sm font-normal float-right">(click to expand)</span></h3>`;
            
            const details = document.createElement('div');
            details.className = 'review-details overflow-hidden'
            details.style.maxHeight = '0';
            details.style.transition = 'max-height 0.5s ease-out';


            incorrectQuestions.forEach(item => {
                const question = item.question;
                const userAnswerKey = item.userAnswer;
                const userAnswerText = userAnswerKey ? question.options[userAnswerKey] : 'Not Answered';
                const correctAnswerText = question.options[question.correct_answer];
                const reviewEl = document.createElement('div');
                reviewEl.className = 'question-review bg-white p-4 mt-4 rounded-lg border border-red-200';
                reviewEl.innerHTML = `
                    <p class="font-semibold text-gray-800 mb-2">Q: ${question.question}</p>
                    <p class="text-red-600">Your Answer: <span class="font-bold">${userAnswerText}</span></p>
                    <p class="text-green-600">Correct Answer: <span class="font-bold">${correctAnswerText}</span></p>
                    ${question.explanation ? `<p class="text-sm text-gray-600 italic mt-3 pt-3 border-t"><em>Explanation: ${question.explanation}</em></p>` : ''}
                `;
                details.appendChild(reviewEl);
            });

            container.appendChild(summary);
            container.appendChild(details);

            summary.onclick = () => {
                if (details.style.maxHeight !== '0px') {
                    details.style.maxHeight = '0px';
                } else {
                    details.style.maxHeight = details.scrollHeight + "px";
                }
            };
        }

        function retakeIncorrectStudyMode() {
            if(currentMode === 'automatic'){
                // When retaking incorrect in auto mode, just retake the questions from the last mini-exam
                 currentQuestions = incorrectQuestions.map(item => item.question);
            } else {
                // For other modes, it creates a new pool of all incorrect questions so far
                 currentQuestions = incorrectQuestions.map(item => item.question);
            }
            shuffleArray(currentQuestions);
            beginExam();
        }

        function resetExam() {
            window.location.href = window.location.pathname + '?page=study_plans';
        }

        function shuffleArray(array) {
            const shuffled = [...array];
            for (let i = shuffled.length - 1; i > 0; i--) {
                const j = Math.floor(Math.random() * (i + 1));
                [shuffled[i], shuffled[j]] = [shuffled[j], shuffled[i]];
            }
            return shuffled;
        }
    </script>
</body>

</html>