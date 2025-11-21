#!/bin/bash
docker stop entra-sync-runner
docker remove entra-sync-runner
docker build -t entra-sync-scheduler .
docker run -d --name entra-sync-runner -v ./config.yaml:/app/config.yaml:ro entra-sync-scheduler
docker exec -it entra-sync-runner /bin/sh -c "cd /app && php sync.php"
