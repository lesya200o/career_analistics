@echo off
schtasks /create /tn "Парсинг вакансий" /tr "C:\Users\User\Downloads\php-8.5.5-Win32-vs17-x64" /sc weekly /d SUN /st 02:00 /ru "System" /f
echo Задача создана!
pause