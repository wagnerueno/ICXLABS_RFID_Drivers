Baixar os arquivos em uma pasta, e executar  
    `sudo ./install.sh`  
Podem aparecer algumas mensagens de erro...  
Após a instalação, que é instantânea, digite para ver o status dos serviços:  
    `sudo systemctl status rfid-api`  
    `sudo systemctl status rfid-reader`  
    `sudo systemctl status rfid-ch-reader`  
São 3 serviços ao todo.  
Um é a API.  
Os outros dois são um para cada tipo de leitor.  
Quando plugar qualquer um deles na usb ou serial ele vai ativar  
A saída de log você vê no syslog  
    `cat /var/log/syslog`  
Como só vai ter leitor de um tipo o outo vai ficar dando erro de 5 em 5 segundos. Só ignorar  
O arquivo json fica em `/usr/shar/rfid/tags.json`  
Os scripts ficam em `/opt/rfid`  
