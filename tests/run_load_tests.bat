@echo off
REM Load testing suite for ClassEngage (Windows)
REM Runs multiple load tests with increasing user counts

REM Configuration
SET SESSION_ID=1
SET COURSE_ID=2
SET CLEANUP=--cleanup

echo =========================================
echo ClassEngage Load Testing Suite
echo =========================================
echo.
echo Session ID: %SESSION_ID%
echo Course ID: %COURSE_ID%
echo Cleanup: %CLEANUP%
echo.

REM Results file
SET RESULTS_FILE=load_test_results_%date:~-4,4%%date:~-10,2%%date:~-7,2%_%time:~0,2%%time:~3,2%%time:~6,2%.txt
SET RESULTS_FILE=%RESULTS_FILE: =0%
echo Results will be saved to: %RESULTS_FILE%
echo.

REM Header for results file
echo ClassEngage Load Test Results - %date% %time% > %RESULTS_FILE%
echo ========================================= >> %RESULTS_FILE%
echo. >> %RESULTS_FILE%

REM Run tests with different user counts
FOR %%u IN (10 25 50 75 100) DO (
    echo Testing with %%u concurrent users...
    echo ---------------------------------------- >> %RESULTS_FILE%
    echo Test: %%u users >> %RESULTS_FILE%
    echo ---------------------------------------- >> %RESULTS_FILE%
    
    REM Run test
    php load_test_api.php --users=%%u --sessionid=%SESSION_ID% --courseid=%COURSE_ID% %CLEANUP% >> %RESULTS_FILE% 2>&1
    
    IF ERRORLEVEL 1 (
        echo [FAILED] Test with %%u users failed
    ) ELSE (
        echo [OK] Test with %%u users completed
    )
    
    echo. >> %RESULTS_FILE%
    echo.
    
    REM Wait between tests
    timeout /t 5 /nobreak > nul
)

echo =========================================
echo All tests completed!
echo Results saved to: %RESULTS_FILE%
echo =========================================
pause
