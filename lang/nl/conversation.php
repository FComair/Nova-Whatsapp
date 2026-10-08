<?php

declare(strict_types=1);

/*
 * WhatsApp-afspraakassistent — berichten naar de eindklant (Nederlands).
 * Vertaling van het Franse referentiebestand: te laten valideren door een Nederlandstalige vóór de lancering.
 * De trefwoorden voor klantberichten staan in het Franse bestand (alle talen samengevoegd); hier zijn alleen de dagnamen nodig.
 */

return [
    'common' => [
        'more' => 'Meer tonen ➡️',
        'more_services' => 'Meer diensten tonen ➡️',
        'more_staff' => 'Meer professionals tonen ➡️',
        'more_times' => 'Meer tijdstippen tonen ➡️',
        'previous_page' => 'Vorige pagina ⬅️',
        'no_preference' => 'Geen voorkeur',
        'not_provided' => 'Niet opgegeven',
        'no_email' => 'niet opgegeven',
        'appointment' => 'Afspraak',
        'invalid_choice' => "⚠️ Ongeldige keuze.\n\nAntwoord met een van de voorgestelde nummers.",
        'invalid_choice_menu' => "⚠️ Ongeldige keuze.\n\n:menu\n\nAntwoord gewoon met het gewenste nummer.",
        'invalid_day' => "Ik heb de dag niet begrepen 😕\n\nVoorbeelden: vandaag, morgen, maandag, dinsdag of 12/09.",
        'session_expired' => "⚠️ Uw aanvraag is verlopen.\n\nWilt u een afspraak maken? Stuur ons dan gewoon een bericht.",
        'error' => "😕 Er is iets misgegaan aan onze kant.\n\nProbeer het over enkele ogenblikken opnieuw.",
    ],

    'booking' => [
        'no_service' => '😕 Er is momenteel geen dienst beschikbaar.',
        'service_menu' => "Prima 👍\n\nU hebt gekozen om een afspraak te maken.\n\nWelke dienst wenst u?\n\n:lines\n\nAntwoord gewoon met het gewenste nummer.",
        'service_page' => "Hier zijn de andere beschikbare diensten:\n\n:lines\n\nAntwoord gewoon met het gewenste nummer.",
        'invalid_service' => "❌ Ongeldige keuze.\n\nAntwoord met een van de nummers in de lijst met diensten.",
        'day_prompt' => "Prima 👍\n\nU hebt gekozen:\n\n💈 :service\n⏱️ Duur: :duration min\n💰 Prijs: :price\n\nOp welke dag wenst u een afspraak te maken?",
        'no_slot_day' => "Prima 👍\n\nDit is uw afspraak tot nu toe:\n\n💈 Dienst: :service\n⏱️ Duur: :duration min\n📅 Dag: :date\n👤 Professional: :staff\n\n❌ Geen tijdstip beschikbaar op deze dag.\n\nKies een andere dag.",
        'staff_menu' => "Prima 👍\n\nDit is uw afspraak tot nu toe:\n\n💈 Dienst: :service\n💰 Prijs: :price\n\n📅 Dag: :date\n\nHebt u een voorkeur voor een professional?\n\n:menu\n\nAntwoord gewoon met het gewenste nummer.",
        'invalid_staff' => "Ik heb uw keuze niet begrepen.\n\nAntwoord met een van de voorgestelde nummers:\n\n:menu",
        'no_slot_staff' => "Prima 👍\n\nDit is uw afspraak tot nu toe:\n\n💈 Dienst: :service\n⏱️ Duur: :duration min\n📅 Dag: :date\n👤 Professional: :staff\n\n❌ Geen tijdstip beschikbaar op deze dag.\n\nKies een andere dag.",
        'time_menu' => "Prima 👍\n\nDit is uw afspraak tot nu toe:\n\n💈 Dienst: :service\n⏱️ Duur: :duration min\n💰 Prijs: :price\n\n📅 Dag: :date\n\n👤 Professional: :staff\n\nKies het uur dat u past:\n\n:slots\n\nAntwoord gewoon met het gewenste nummer.",
        'time_page' => "🕐 Hier zijn de volgende tijdstippen\n\n📅 :date\n💈 Dienst: :service\n👤 Professional: :staff\n\n:slots\n\nAntwoord gewoon met het gewenste nummer.",
        'name_prompt' => "Prima 👍\n\nUw afspraak is bijna bevestigd.\n\n📋 Samenvatting:\n\n💈 Dienst: :service\n⏱️ Duur: :duration min\n👤 Professional: :staff\n\n📅 Afspraak: :date\n🕐 Uur: :time\n\n👤 Voor wie is deze afspraak?\n\nGeef de voor- en achternaam van de persoon die naar de afspraak komt.",
        'invalid_name' => "Ik heb de naam niet begrepen 😕\n\nGeef de voor- en achternaam van de persoon die naar de afspraak komt.",
        'email_prompt' => "Prima 👍\n\nDank u :name.\n\n📧 *Wilt u uw afspraak in uw agenda ontvangen?*\n\nZo ja, geef gewoon uw e-mailadres.\n\nVoorbeeld:\n📩 naam@gmail.com\n\n0️⃣ Doorgaan zonder e-mail\n\n_Uw adres wordt alleen gebruikt voor de uitnodiging van deze afspraak._",
        'invalid_email' => "Dit e-mailadres lijkt niet geldig 😕\n\nVoorbeeld: naam@gmail.com\n\nOf antwoord 0️⃣ om door te gaan zonder e-mail.",
        'summary' => "📋 *Controleer uw afspraak:*\n\n👤 Persoon: :name\n\n💈 Dienst: :service\n⏱️ Duur: :duration min\n💰 Prijs: :price\n\n👤 Professional: :staff\n\n📅 Afspraak: :date\n🕐 Uur: :time\n\n📧 E-mail: :email\n\nWat wenst u te doen?\n\n1️⃣ Bevestigen ✅\n2️⃣ Wijzigen ✏️\n3️⃣ Annuleren ❌",
        'abandoned' => "❌ Uw afspraakaanvraag is geannuleerd.\n\nWilt u een nieuwe afspraak maken? Stuur ons dan gewoon een bericht.",
        'refused' => "😕 Ik kon deze afspraak niet registreren.\n\nNeem rechtstreeks contact op met :business.",
        'confirmed' => "🎉 Perfect :name!\n\nUw afspraak is bevestigd.\n\n📅 Datum: :date\n🕐 Uur: :start - :end\n💼 Dienst: :service\n⏱️ Duur: :duration min\n👤 Professional: :staff\n💰 Prijs: :price\n\n📍 :business\n:address\n\nBedankt voor uw vertrouwen 🙏\n\nTot binnenkort bij :business! 😊",
    ],

    'conflict' => [
        'slots' => "😔 Sorry:name,\n\nHet tijdstip :time is helaas niet meer beschikbaar.\n\n🏢 :business\n💈 Dienst: :service\n👤 Professional: :staff\n\n📅 :date\n\nDit zijn de nog beschikbare uren:\n\n:slots\n\n💬 Antwoord met het nummer of rechtstreeks met het uur dat u past.\n\nBijvoorbeeld: 1 of :example",
        'none' => "😔 Sorry:name,\n\nHet tijdstip :time is helaas niet meer beschikbaar.\n\n🏢 :business\n💈 Dienst: :service\n👤 Professional: :staff\n\n📅 :date\n\nEr is helaas geen ander tijdstip meer beschikbaar op deze dag.\n\nKies een andere dag.",
    ],

    'modify' => [
        'none' => "Ik heb geen komende afspraak gevonden die gewijzigd kan worden.\n\nWilt u een nieuwe afspraak maken? Zeg dan bijvoorbeeld:\n\n“Ik wil graag een afspraak maken”.",
        'too_late' => "⏰ Deze afspraak kan niet meer online gewijzigd worden.\n\nNeem rechtstreeks contact op met :business.",
        'refused' => "😕 Ik kon deze wijziging niet registreren.\n\nNeem rechtstreeks contact op met :business.",
        'choose' => "U hebt meerdere komende afspraken.\n\nWelke wenst u te wijzigen?\n\n:lines\n\nAntwoord gewoon met het nummer van de afspraak.",
        'choose_line' => ":number :name\n💼 :service\n👤 :staff\n📅 :date\n🕐 :time",
        'invalid_appointment' => "De opgegeven keuze is niet geldig.\n\nAntwoord met het nummer van de afspraak die u wilt wijzigen.",
        'menu_single' => "Dit is de afspraak die u wilt wijzigen:\n\n:details\n\nWat wenst u te wijzigen?\n\n1️⃣ De dienst\n2️⃣ De professional\n3️⃣ De dag\n4️⃣ Het uur\n5️⃣ De wijziging annuleren\n\nAntwoord gewoon met het nummer van uw keuze.",
        'menu_chosen' => "✏️ Welk onderdeel wenst u te wijzigen?\n\n:details\n\nAntwoord gewoon met het gewenste nummer:\n\n1️⃣ Dienst\n2️⃣ Professional\n3️⃣ Dag\n4️⃣ Uur\n5️⃣ De wijziging annuleren",
        'menu_again' => "✏️ Wijziging van uw afspraak\n\nHallo:spaced,\n\nDit is uw huidige afspraak:\n\n:details\n\nWat wenst u te wijzigen?\n\n1️⃣ Dienst\n2️⃣ Professional\n3️⃣ Datum\n4️⃣ Uur\n5️⃣ De afspraak behouden\n\nAntwoord met het overeenkomstige nummer.",
        'menu_draft' => "✏️ Wijziging van uw reservatie\n\nHallo:spaced,:selection\n\nWat wenst u te wijzigen?\n\n1️⃣ Dienst\n2️⃣ Professional\n3️⃣ Datum\n4️⃣ Uur\n5️⃣ Terug\n\nAntwoord met het overeenkomstige nummer.",
        'detail_service' => '💈 Dienst: :value',
        'detail_staff' => '👤 Professional: :value',
        'detail_date' => '📅 Datum: :value',
        'detail_time' => '🕐 Uur: :value',
        'service_menu' => "Prima 👍\n\n:whoU hebt gekozen om de dienst te wijzigen.\n\nKies de nieuwe dienst:\n\n:lines\n\nAntwoord met het overeenkomstige nummer.",
        'service_page' => "🔄 Hier zijn de andere beschikbare diensten\n\n:whoKies de nieuwe dienst:\n\n:lines\n\nAntwoord met het overeenkomstige nummer.",
        'no_service' => '😕 Er is momenteel geen dienst beschikbaar.',
        'no_other_staff' => "ℹ️ Wijziging van de professional\n\n:who:currentEr is momenteel geen andere professional beschikbaar.\n\nWat wenst u te doen?\n\n1️⃣ Iets anders wijzigen\n2️⃣ De reservatie behouden",
        'current_staff' => "Momenteel gekozen professional:\n👤 :staff\n\n",
        'staff_menu' => "👤 Wijziging van de professional\n\n:who:currentKies uw nieuwe professional:\n\n:menu\n\nAntwoord met het overeenkomstige nummer.",
        'day_prompt' => "📅 Wijziging van de datum\n\nHallo:spaced,:selection\n\nWelke nieuwe dag wenst u?\n\nVoorbeelden: vandaag, morgen, maandag, dinsdag of 12/09.",
        'choose_time' => "🕐 Kies uw nieuwe uur\n\nDit zijn de beschikbare tijdstippen voor:\n\n📅 :date\n\n:slots\n\n💬 Antwoord met het nummer of rechtstreeks met het uur dat u past.\n\nBijvoorbeeld: 1 of :example",
        'no_slot_day' => "😕 Geen tijdstip beschikbaar\n\nEr is helaas geen tijdstip meer beschikbaar voor:\n\n📅 :date\n💈 :service\n\n📅 Kies een andere dag.",
        'slots_page' => "🕒 Dit zijn de volgende uren:\n\n:slots\n\n💬 Antwoord met het nummer of rechtstreeks met het uur dat u past.\n\nBijvoorbeeld: 1 of :example",
        'invalid_slot' => "⚠️ Sorry:name,\n\nIk heb uw keuze niet begrepen.\n\nAntwoord met een van de voorgestelde nummers of rechtstreeks met het gewenste uur.\n\n:slots\n\n💬 Bijvoorbeeld: 1 of :example",
        'prepared' => "✅ Wijziging voorbereid.\n\nDit is uw bijgewerkte afspraak:\n\n👤 Persoon: :name\n\n💈 Dienst: :service\n⏱️ Duur: :duration min\n💰 Prijs: :price\n\n👤 Professional: :staff\n\n📅 Afspraak: :date\n🕐 Uur: :time\n\nWilt u deze wijziging bevestigen?\n\n1️⃣ Bevestigen ✅\n2️⃣ Wijzigen ✏️\n3️⃣ Annuleren ❌",
        'staff_done' => "✅ Uw afspraak is bijgewerkt.\n\nDit is uw bijgewerkte afspraak:\n\n👤 Persoon: :name\n\n💈 Dienst: :service\n⏱️ Duur: :duration min\n💰 Prijs: :price\n\n👤 Professional: :staff\n\n📅 Afspraak: :date\n🕐 Uur: :time\n\nWilt u deze afspraak bevestigen?\n\n1️⃣ Bevestigen ✅\n2️⃣ Wijzigen ✏️\n3️⃣ Annuleren ❌",
        'keep_time' => "🎉 Perfect:spaced!\n\nUw vorige uur is nog beschikbaar.\n\nDit is uw nieuwe afspraak:\n\n📅 :date\n🕐 Uur: :time\n💈 Dienst: :service\n👤 Professional: :staff\n\nWat wenst u te doen?\n\n1️⃣ De afspraak bevestigen\n2️⃣ Opnieuw wijzigen",
        'time_done' => "👍 Perfect:spaced!\n\nU hebt een nieuw uur gekozen.\n\nDit is de samenvatting van uw afspraak:\n\n📅 Datum: :date\n🕒 Uur: :time\n💼 Dienst: :service\n👤 Professional: :staff\n\nControleer deze gegevens.\n\n1️⃣ De afspraak bevestigen\n2️⃣ Opnieuw wijzigen",
        'staff_busy' => "❌ :staff is niet beschikbaar om :time op :date.\n\nDit zijn de nog beschikbare tijdstippen:\n\n:slots\n\n💬 Antwoord met het nummer of rechtstreeks met het uur dat u past.",
        'no_availability_staff' => "Prima 👍\n\nHelaas heeft :staff geen beschikbaarheid meer op :date.\n\nWilt u de beschikbaarheid van de volgende dagen bekijken?\n\nAntwoord gewoon:\n\n1️⃣ Ja\n2️⃣ Nee",
        'staff_unavailable' => "😔 Sorry:name,\n\n:reason\n\n🏢 :business\n\nDit zijn de eerstvolgende beschikbaarheden van :staff:\n\n:suggestions\n\n💬 Antwoord gewoon met de dag die u past.",
        'reason_closed' => 'Er is geen beschikbaarheid op :date.',
        'reason_none_left' => ':staff heeft geen beschikbaarheid meer op :date.',
        'nothing_found' => "😕 Ik vond binnenkort geen beschikbaarheid.\n\nNeem rechtstreeks contact op met :business.",
        'done' => "✅ Uw afspraak is bijgewerkt en bevestigd.\n\n👤 Persoon: :name\n\n💈 Dienst: :service\n💰 Prijs: :price\n\n👤 Professional: :staff\n\n📅 Afspraak: :date\n🕐 Uur: :time\n\nTot binnenkort 👋",
        'kept' => "✅ Uw afspraak blijft behouden.\n\n📅 Datum: :date\n🕒 Uur: :time\n💼 Dienst: :service\n👤 Professional: :staff\n\nWe verwachten u bij :business.\n\nTot binnenkort 😊",
    ],

    'cancel' => [
        'none' => "❌ Ik heb geen komende afspraak op uw naam gevonden.\n\nWilt u een nieuwe afspraak maken? Stuur ons dan gewoon een bericht.",
        'choose' => "📅 *Ik heb meerdere afspraken gevonden.*\n\n:lines\n\nWelke wenst u te annuleren?\n\nAntwoord gewoon met het nummer van de afspraak.",
        'choose_line' => ":number *Afspraak :index*\n📅 :date\n🕐 :time\n💼 :service\n👤 :staff",
        'invalid_appointment' => "De opgegeven keuze is niet geldig.\n\nAntwoord met het nummer van de afspraak die u wilt annuleren.",
        'confirm' => "⚠️ *Bevestiging van annulering*\n\nU wilt deze afspraak annuleren:\n\n📅 Datum: :date\n🕐 Uur: :start - :end\n💼 Dienst: :service\n👤 Professional: :staff\n\n*Bevestigt u de annulering?*\n\n1️⃣ Ja, mijn afspraak annuleren ❌\n2️⃣ Nee, mijn afspraak behouden ✅",
        'done' => "✅ Uw afspraak is geannuleerd.\n\n📅 :date\n🕒 :time\n💇 :service\n👤 :staff\n\nDit tijdstip is nu weer beschikbaar voor een nieuwe reservatie.\n\nBedankt voor uw vertrouwen.\n\nWilt u een nieuwe afspraak maken? Stuur ons dan gewoon een bericht.",
        'too_late' => "⏰ Deze afspraak kan niet meer online geannuleerd worden.\n\nNeem rechtstreeks contact op met :business.",
    ],

    'menu' => [
        'greeting_new' => "Hallo:spaced 👋\n\nWelkom bij :business.\n\nWat wenst u te doen?\n\n1️⃣ Een afspraak maken\n2️⃣ Een vraag stellen",
        'greeting_existing' => "Hallo:spaced 👋\n\nU hebt een afspraak gepland:\n\n💈 :service:staff_line\n📅 :date\n🕐 :time_h\n\nWat wenst u te doen?\n\n1️⃣ Mijn aanwezigheid bevestigen ✅\n2️⃣ Mijn afspraak wijzigen ✏️\n3️⃣ Mijn afspraak annuleren ❌\n4️⃣ Een nieuwe afspraak maken ➕\n5️⃣ Een vraag stellen",
        'existing_appointment' => "ℹ️ U hebt al een afspraak gepland.\n\n👤 Persoon: :name\n💈 Dienst: :service\n👤 Professional: :staff\n📅 Afspraak: :date\n🕐 Uur: :time\n\nWat wenst u te doen?\n\n1️⃣ Deze afspraak wijzigen ✏️\n2️⃣ Een nieuwe afspraak maken ➕\n3️⃣ Annuleren ❌",
        'with_staff' => '👤 :staff',
        'clarify' => "Ik heb uw vraag niet goed begrepen 😊\n\nWat wenst u te doen?\n\n1️⃣ Een afspraak maken\n2️⃣ Een afspraak wijzigen\n3️⃣ Een afspraak annuleren\n4️⃣ Een vraag stellen\n5️⃣ Met iemand spreken\n6️⃣ Afsluiten",
        'quit' => "Geen probleem 👋\n\nHet gesprek is afgesloten. Stuur ons gerust een bericht als u ons nodig hebt.",
        'handoff' => "Natuurlijk 🙏\n\nIk geef uw vraag door aan het team van :business. Iemand antwoordt u zo snel mogelijk.",
        'presence_confirmed' => "✅ Aanwezigheid bevestigd\n\nHallo :name,\n\nBedankt, uw aanwezigheid is bevestigd.\n\n📅 Datum: :date\n🕒 Uur: :time\n💼 Dienst: :service\n👤 Professional: :staff\n\n🏢 :business\n\nWe verwachten u. Tot binnenkort!",
        'reminder_invalid' => "Ik heb uw antwoord niet begrepen.\n\n1️⃣ Mijn aanwezigheid bevestigen ✅\n2️⃣ Mijn afspraak annuleren ❌",
    ],

    'faq' => [
        'ask' => "Natuurlijk 😊\n\nWat is uw vraag?",
        'menu' => "Wat wenst u te doen?\n\n1️⃣ Een afspraak maken\n2️⃣ Een vraag stellen",
        'unavailable' => "Ik heb deze informatie momenteel niet 🙏\n\nNeem rechtstreeks contact op met :business.",
    ],

    'names' => [
        'weekdays' => ['zondag', 'maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag'],
        'months' => ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'],
    ],

    'booking_note' => 'Geboekt via WhatsApp voor :name',
    'service_change_reason' => 'Wijziging van dienst gevraagd door de klant (WhatsApp)',
    'cancel_reason' => 'Geannuleerd door de klant (WhatsApp)',

    'keywords' => [
        'weekdays' => ['zondag', 'maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag'],
    ],
];
