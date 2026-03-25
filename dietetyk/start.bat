@echo off
echo ============================================
echo   DIEtetyk - AI Coach
echo   http://localhost:8080
echo ============================================
echo.
php -S localhost:8080 -t public public/router.php
