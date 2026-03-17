FROM python:3.11-slim

WORKDIR /srv/agent

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY . .

ENTRYPOINT ["python", "/srv/agent/main.py"]
