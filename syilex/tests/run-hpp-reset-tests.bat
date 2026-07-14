@echo off
echo ============================================
echo Running HPP Reset Feature Tests
echo ============================================
echo.

echo [1/4] Running Unit Tests - MasterProduk HPP Reset...
php artisan test --filter=MasterProdukHppResetTest
echo.

echo [2/4] Running Unit Tests - StockCard Transaction Types...
php artisan test --filter=StockCardTransactionTypesTest
echo.

echo [3/4] Running Feature Tests - Adjustment HPP Reset...
php artisan test --filter=AdjustmentHppResetTest
echo.

echo [4/4] Running Feature Tests - Repack HPP Reset...
php artisan test --filter=RepackHppResetTest
echo.

echo ============================================
echo Running API Tests...
echo ============================================
php artisan test --filter=StockCardApiHppResetTest
echo.

echo ============================================
echo All Tests Completed!
echo ============================================
pause
