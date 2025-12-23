import csv
import random

ROWS = 100_000

with open("people.csv", "w", newline="", encoding="utf-8") as f:
    writer = csv.writer(f)
    writer.writerow(["name", "age", "gender", "feedback"])  # header

    for i in range(1, ROWS + 1):
        writer.writerow([f"name_{i}", random.randint(1, 100), random.choice(["Male", "Female", "Other"]), f"Feedback {i}"])

print("people.csv generated with 100,000 rows")
