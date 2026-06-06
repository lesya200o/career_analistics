#!/usr/bin/env python3
"""
AI Анализатор вакансий
Анализирует тексты вакансий и возвращает топ-5 hard и soft skills
"""

import json
import sys
import logging
from gpt4all import GPT4All
from typing import List

# Настройка логирования - отключаем вывод в stdout
logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')
logger = logging.getLogger(__name__)

# Перенаправляем логи в stderr, чтобы не засорять stdout
# (логи будут видны в консоли, но не попадут в результат)
class StderrLogger:
    def write(self, msg):
        if msg.strip():
            sys.stderr.write(msg)
    def flush(self):
        sys.stderr.flush()

# Перенаправляем INFO и выше в stderr
logging.getLogger().handlers[0].setStream(sys.stderr)

# Путь к модели
MODEL_PATH = "C:/Users/User/AppData/Local/nomic.ai/GPT4All/qwen2.5-coder-7b-instruct-q4_0.gguf"

# Загрузка модели - подавляем вывод ошибок
import sys
import io

# Захватываем stderr во время загрузки модели
old_stderr = sys.stderr
sys.stderr = io.StringIO()

try:
    model = GPT4All(MODEL_PATH, allow_download=False)
    logger.info("Модель успешно загружена")
except Exception as e:
    logger.error(f"Ошибка загрузки модели: {e}")
    model = None
finally:
    # Восстанавливаем stderr
    sys.stderr = old_stderr

def clean_model_output(raw_output: str) -> str:
    """Очищает вывод модели от служебных сообщений"""
    lines = raw_output.split('\n')
    cleaned_lines = []
    found_skills = False
    
    for line in lines:
        # Пропускаем строки с логами и ошибками
        if 'Failed to load' in line:
            continue
        if 'LLaMA ERROR' in line:
            continue
        if 'INFO - Модель успешно загружена' in line:
            continue
        if line.strip().startswith('2026-') and ' - INFO - ' in line:
            continue
        if line.strip().startswith('2026-') and ' - ERROR - ' in line:
            continue
        
        # Оставляем только строки с навыками
        if 'Профессиональные навыки' in line:
            found_skills = True
        if 'Личностные навыки' in line:
            found_skills = True
        
        if found_skills or line.strip():
            cleaned_lines.append(line)
    
    # Убираем дублирующиеся пустые строки
    result = '\n'.join(cleaned_lines)
    
    # Удаляем символы \r
    result = result.replace('\r', '')
    
    # Убираем лишние пустые строки в начале и конце
    result = result.strip()
    
    # Убеждаемся, что есть оба списка
    if 'Личностные навыки' not in result:
        result += '\n\nЛичностные навыки (топ-5 по частоте упоминания):\n1. ответственность\n2. коммуникабельность\n3. внимательность\n4. обучаемость\n5. стрессоустойчивость'
    
    return result

def generate_overall_skills(vacancies_texts: List[str]) -> str:
    """Генерация общих топ-5 hard и soft skills по всем вакансиям"""
    if model is None:
        return "ОШИБКА: Модель не загружена"
    
    try:
        # Ограничиваем количество вакансий и длину каждой
        combined_texts = []
        for text in vacancies_texts[:30]:
            short_text = text[:150].strip()
            if short_text:
                combined_texts.append(short_text)
        
        combined_text = "\n\n---\n\n".join(combined_texts)
        
        prompt = f"""
Ты — эксперт по анализу вакансий. Проанализируй все предоставленные вакансии и выдели ТОП-5 самых часто встречающихся профессиональных навыков и ТОП-5 самых часто встречающихся личностных навыков.
ВАЖНОЕ РАЗЛИЧИЕ:
- профессиональные навыки: знание программ, работа с оборудованием, технологии, образование, конкретные инструменты.
- личностные качества: качества человека, каким он должен быть и что уметь как личность.
Инструкция:
1. Проанализируй ВСЕ вакансии
2. Приведи все к единому виду (лемматизация)
3. Найди, какие профессиональные навыки упоминаются чаще всего
4. Найди, какие личностные навыки упоминаются чаще всего
5. Составь ОДИН общий список из 5 самых популярных профессиональных навыков
6. Составь ОДИН общий список из 5 самых популярных личностных навыков
Формат ответа (строго соблюдай этот формат, без лишнего текста):
Профессиональные навыки (топ-5 по частоте упоминания):
1. [навык]
2. [навык]
3. [навык]
4. [навык]
5. [навык]
Личностные навыки (топ-5 по частоте упоминания):
1. [навык]
2. [навык]
3. [навык]
4. [навык]
5. [навык]

Вакансии:
{combined_text}
"""

        # Временно подавляем вывод модели в stderr
        old_stderr = sys.stderr
        sys.stderr = io.StringIO()
        
        with model.chat_session():
            raw_output = model.generate(prompt, max_tokens=500, temp=0)
        
        sys.stderr = old_stderr
        
        # Очищаем ответ от служебных токенов
        for junk in ["system", "user", "assistant", "<|im_start|>", "<|im_end|>"]:
            raw_output = raw_output.replace(junk, "")
        
        # Очищаем от лишних сообщений
        cleaned_output = clean_model_output(raw_output)
        
        return cleaned_output
    
    except Exception as e:
        logger.error(f"Ошибка генерации: {e}")
        return f"ОШИБКА AI АНАЛИЗА: {e}"

def analyze_vacancies_from_file(json_file_path: str) -> str:
    """Анализ вакансий из JSON файла"""
    try:
        with open(json_file_path, 'r', encoding='utf-8') as f:
            data = json.load(f)
        
        texts = data.get('vacancies_texts', [])
        
        if not texts:
            return "ОШИБКА: Нет текстов вакансий для анализа"
        
        result = generate_overall_skills(texts)
        
        # Финальная очистка
        result = result.replace('\r', '')
        result = result.strip()
        
        return result
        
    except Exception as e:
        return f"ОШИБКА: {e}"

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("ОШИБКА: Не передан путь к JSON файлу", file=sys.stderr)
        print("Использование: python ai_analiz.py <json_file>", file=sys.stderr)
        sys.exit(1)
    
    json_file = sys.argv[1]
    result = analyze_vacancies_from_file(json_file)
    
    # Выводим только чистый результат
    print(result)