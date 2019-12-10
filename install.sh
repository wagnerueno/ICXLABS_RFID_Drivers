#!/bin/bash
systemctl stop rfid-api.service
systemctl stop rfid-reader.service
systemctl stop rfid-ch-reader.service
cp rfid-*.service /etc/systemd/system
mkdir /opt/rfid
cp rfid_*.php /opt/rfid
mkdir /usr/share/rfid
systemctl enable rfid-api.service
systemctl enable rfid-reader.service
systemctl enable rfid-ch-reader.service
systemctl start rfid-api.service
systemctl start rfid-reader.service
systemctl start rfid-ch-reader.service
