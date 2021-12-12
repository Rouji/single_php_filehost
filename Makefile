build:
	mkdir -p files
	docker build -t lightphp .
start:
	sudo docker-compose up -d
stop:
	sudo docker-compose rm -s
