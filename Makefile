build:
	docker build -t lightphp .
run:
	mkdir -p files
	docker run --rm -p 80:80 -v ${PWD}/files:/var/www/files lightphp
rm:
	docker rmi lightphp
	rm -rf files