#!/bin/bash
# Download currency flag icons from CDN

mkdir -p assets/images/currencies

# Currency flags (country flags for currencies)
curl -o assets/images/currencies/USD.svg "https://flagcdn.com/us.svg"
curl -o assets/images/currencies/EUR.svg "https://flagcdn.com/eu.svg"
curl -o assets/images/currencies/GBP.svg "https://flagcdn.com/gb.svg"
curl -o assets/images/currencies/CHF.svg "https://flagcdn.com/ch.svg"
curl -o assets/images/currencies/CAD.svg "https://flagcdn.com/ca.svg"
curl -o assets/images/currencies/AUD.svg "https://flagcdn.com/au.svg"
curl -o assets/images/currencies/JPY.svg "https://flagcdn.com/jp.svg"
curl -o assets/images/currencies/CNY.svg "https://flagcdn.com/cn.svg"
curl -o assets/images/currencies/SAR.svg "https://flagcdn.com/sa.svg"
curl -o assets/images/currencies/AED.svg "https://flagcdn.com/ae.svg"
curl -o assets/images/currencies/KWD.svg "https://flagcdn.com/kw.svg"
curl -o assets/images/currencies/DKK.svg "https://flagcdn.com/dk.svg"
curl -o assets/images/currencies/SEK.svg "https://flagcdn.com/se.svg"
curl -o assets/images/currencies/NOK.svg "https://flagcdn.com/no.svg"
curl -o assets/images/currencies/RUB.svg "https://flagcdn.com/ru.svg"
curl -o assets/images/currencies/RON.svg "https://flagcdn.com/ro.svg"

# Precious metals - use proper metal icons
echo "Downloading precious metal icons..."

# Gold (Altın) - Gold bar/ingot icon
curl -o assets/images/currencies/XAU.png "https://cdn-icons-png.flaticon.com/128/2913/2913133.png"

# Silver (Gümüş) - Silver bar/ingot icon  
curl -o assets/images/currencies/XAG.png "https://cdn-icons-png.flaticon.com/128/2913/2913134.png"

# Platinum (Platin) - Platinum icon
curl -o assets/images/currencies/XPT.png "https://cdn-icons-png.flaticon.com/128/9195/9195842.png"

# Palladium (Paladyum) - Palladium icon
curl -o assets/images/currencies/XPD.png "https://cdn-icons-png.flaticon.com/128/9195/9195843.png"

echo "Currency icons downloaded!"
