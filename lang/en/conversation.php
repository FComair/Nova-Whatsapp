<?php

declare(strict_types=1);

/*
 * WhatsApp booking assistant — messages sent to the end customer (English).
 * Translation of the French reference file: to be validated by a native speaker before going live.
 * Keyword lists for customer messages live in the French file (all languages are merged there); only the weekday names are needed here.
 */

return [
    'common' => [
        'more' => 'See more ➡️',
        'more_services' => 'See more services ➡️',
        'more_staff' => 'See more professionals ➡️',
        'more_times' => 'See more time slots ➡️',
        'previous_page' => 'Previous page ⬅️',
        'no_preference' => 'No preference',
        'not_provided' => 'Not provided',
        'no_email' => 'not provided',
        'appointment' => 'Appointment',
        'invalid_choice' => "⚠️ Invalid choice.\n\nPlease reply with one of the numbers shown.",
        'invalid_choice_menu' => "⚠️ Invalid choice.\n\n:menu\n\nSimply reply with the number you want.",
        'invalid_day' => "I didn't understand the day 😕\n\nExamples: today, tomorrow, Monday, Tuesday or 12/09.",
        'session_expired' => "⚠️ Your request has expired.\n\nIf you would like to book an appointment, just send us a message.",
        'error' => "😕 Something went wrong on our side.\n\nPlease try again in a few moments.",
    ],

    'booking' => [
        'no_service' => '😕 No service is currently available.',
        'service_menu' => "Great 👍\n\nYou chose to book an appointment.\n\nWhich service would you like?\n\n:lines\n\nSimply reply with the number you want.",
        'service_page' => "Here are the other available services:\n\n:lines\n\nSimply reply with the number you want.",
        'invalid_service' => "❌ Invalid choice.\n\nPlease reply with one of the numbers in the list of services.",
        'day_prompt' => "Great 👍\n\nYou chose:\n\n💈 :service\n⏱️ Duration: :duration min\n💰 Price: :price\n\nWhich day would you like to book?",
        'no_slot_day' => "Great 👍\n\nHere is your appointment so far:\n\n💈 Service: :service\n⏱️ Duration: :duration min\n📅 Day: :date\n👤 Professional: :staff\n\n❌ No time slot available for this day.\n\nPlease choose another day.",
        'staff_menu' => "Great 👍\n\nHere is your appointment so far:\n\n💈 Service: :service\n💰 Price: :price\n\n📅 Day: :date\n\nDo you have a preference for a professional?\n\n:menu\n\nSimply reply with the number you want.",
        'invalid_staff' => "I didn't understand your choice.\n\nPlease reply with one of the numbers shown:\n\n:menu",
        'no_slot_staff' => "Great 👍\n\nHere is your appointment so far:\n\n💈 Service: :service\n⏱️ Duration: :duration min\n📅 Day: :date\n👤 Professional: :staff\n\n❌ No time slot available for this day.\n\nPlease choose another day.",
        'time_menu' => "Great 👍\n\nHere is your appointment so far:\n\n💈 Service: :service\n⏱️ Duration: :duration min\n💰 Price: :price\n\n📅 Day: :date\n\n👤 Professional: :staff\n\nChoose the time that suits you:\n\n:slots\n\nSimply reply with the number you want.",
        'time_page' => "🕐 Here are the next time slots\n\n📅 :date\n💈 Service: :service\n👤 Professional: :staff\n\n:slots\n\nSimply reply with the number you want.",
        'name_prompt' => "Great 👍\n\nYour appointment is almost confirmed.\n\n📋 Summary:\n\n💈 Service: :service\n⏱️ Duration: :duration min\n👤 Professional: :staff\n\n📅 Appointment: :date\n🕐 Time: :time\n\n👤 Who is this appointment for?\n\nPlease give the first and last name of the person who will come to the appointment.",
        'invalid_name' => "I didn't understand the name 😕\n\nPlease give the first and last name of the person who will come to the appointment.",
        'email_prompt' => "Great 👍\n\nThank you :name.\n\n📧 *Would you like to receive your appointment in your calendar?*\n\nIf so, simply type your e-mail address.\n\nExample:\n📩 name@gmail.com\n\n0️⃣ Continue without e-mail\n\n_Your address will only be used for the invitation linked to this appointment._",
        'invalid_email' => "This e-mail address does not look valid 😕\n\nExample: name@gmail.com\n\nOr reply 0️⃣ to continue without e-mail.",
        'summary' => "📋 *Please check your appointment:*\n\n👤 Person: :name\n\n💈 Service: :service\n⏱️ Duration: :duration min\n💰 Price: :price\n\n👤 Professional: :staff\n\n📅 Appointment: :date\n🕐 Time: :time\n\n📧 E-mail: :email\n\nWhat would you like to do?\n\n1️⃣ Confirm ✅\n2️⃣ Change ✏️\n3️⃣ Cancel ❌",
        'abandoned' => "❌ Your appointment request has been cancelled.\n\nIf you would like to book a new appointment, just send us a message.",
        'refused' => "😕 I could not save this appointment.\n\nPlease contact :business directly.",
        'confirmed' => "🎉 Perfect :name!\n\nYour appointment is confirmed.\n\n📅 Date: :date\n🕐 Time: :start - :end\n💼 Service: :service\n⏱️ Duration: :duration min\n👤 Professional: :staff\n💰 Price: :price\n\n📍 :business\n:address\n\nThank you for your trust 🙏\n\nSee you soon at :business! 😊",
    ],

    'conflict' => [
        'slots' => "😔 Sorry:name,\n\nThe :time slot is unfortunately no longer available.\n\n🏢 :business\n💈 Service: :service\n👤 Professional: :staff\n\n📅 :date\n\nHere are the times still available:\n\n:slots\n\n💬 Reply with the number or directly with the time that suits you.\n\nFor example: 1 or :example",
        'none' => "😔 Sorry:name,\n\nThe :time slot is unfortunately no longer available.\n\n🏢 :business\n💈 Service: :service\n👤 Professional: :staff\n\n📅 :date\n\nThere is unfortunately no other time slot available for this day.\n\nPlease choose another day.",
    ],

    'modify' => [
        'none' => "I could not find any upcoming appointment that can be changed.\n\nIf you would like to book a new appointment, simply say for example:\n\n“I would like to book an appointment”.",
        'too_late' => "⏰ This appointment can no longer be changed online.\n\nPlease contact :business directly.",
        'refused' => "😕 I could not save this change.\n\nPlease contact :business directly.",
        'choose' => "You have several upcoming appointments.\n\nWhich one would you like to change?\n\n:lines\n\nSimply reply with the appointment number.",
        'choose_line' => ":number :name\n💼 :service\n👤 :staff\n📅 :date\n🕐 :time",
        'invalid_appointment' => "The choice is not valid.\n\nPlease reply with the number of the appointment you want to change.",
        'menu_single' => "Here is the appointment you want to change:\n\n:details\n\nWhat would you like to change?\n\n1️⃣ The service\n2️⃣ The professional\n3️⃣ The day\n4️⃣ The time\n5️⃣ Cancel the change\n\nSimply reply with the number of your choice.",
        'menu_chosen' => "✏️ Which item would you like to change?\n\n:details\n\nSimply reply with the number you want:\n\n1️⃣ Service\n2️⃣ Professional\n3️⃣ Day\n4️⃣ Time\n5️⃣ Cancel the change",
        'menu_again' => "✏️ Changing your appointment\n\nHello:spaced,\n\nHere is your current appointment:\n\n:details\n\nWhat would you like to change?\n\n1️⃣ Service\n2️⃣ Professional\n3️⃣ Date\n4️⃣ Time\n5️⃣ Keep the appointment\n\nReply with the matching number.",
        'menu_draft' => "✏️ Changing your booking\n\nHello:spaced,:selection\n\nWhat would you like to change?\n\n1️⃣ Service\n2️⃣ Professional\n3️⃣ Date\n4️⃣ Time\n5️⃣ Back\n\nReply with the matching number.",
        'detail_service' => '💈 Service: :value',
        'detail_staff' => '👤 Professional: :value',
        'detail_date' => '📅 Date: :value',
        'detail_time' => '🕐 Time: :value',
        'service_menu' => "Great 👍\n\n:whoYou chose to change the service.\n\nSelect the new service:\n\n:lines\n\nReply with the matching number.",
        'service_page' => "🔄 Here are the other available services\n\n:whoSelect the new service:\n\n:lines\n\nReply with the matching number.",
        'no_service' => '😕 No service is currently available.',
        'no_other_staff' => "ℹ️ Changing the professional\n\n:who:currentNo other professional is currently available.\n\nWhat would you like to do?\n\n1️⃣ Change something else\n2️⃣ Keep the booking",
        'current_staff' => "Currently selected professional:\n👤 :staff\n\n",
        'staff_menu' => "👤 Changing the professional\n\n:who:currentSelect your new professional:\n\n:menu\n\nReply with the matching number.",
        'day_prompt' => "📅 Changing the date\n\nHello:spaced,:selection\n\nWhich new day would you like?\n\nExamples: today, tomorrow, Monday, Tuesday or 12/09.",
        'choose_time' => "🕐 Choose your new time\n\nHere are the available slots for:\n\n📅 :date\n\n:slots\n\n💬 Reply with the number or directly with the time that suits you.\n\nFor example: 1 or :example",
        'no_slot_day' => "😕 No time slot available\n\nThere is unfortunately no time slot left for:\n\n📅 :date\n💈 :service\n\n📅 Please choose another day.",
        'slots_page' => "🕒 Here are the next times:\n\n:slots\n\n💬 Reply with the number or directly with the time that suits you.\n\nFor example: 1 or :example",
        'invalid_slot' => "⚠️ Sorry:name,\n\nI didn't understand your choice.\n\nPlease reply with one of the numbers shown or directly with the time you want.\n\n:slots\n\n💬 For example: 1 or :example",
        'prepared' => "✅ Change prepared.\n\nHere is your updated appointment:\n\n👤 Person: :name\n\n💈 Service: :service\n⏱️ Duration: :duration min\n💰 Price: :price\n\n👤 Professional: :staff\n\n📅 Appointment: :date\n🕐 Time: :time\n\nWould you like to confirm this change?\n\n1️⃣ Confirm ✅\n2️⃣ Change ✏️\n3️⃣ Cancel ❌",
        'staff_done' => "✅ Your appointment has been updated.\n\nHere is your updated appointment:\n\n👤 Person: :name\n\n💈 Service: :service\n⏱️ Duration: :duration min\n💰 Price: :price\n\n👤 Professional: :staff\n\n📅 Appointment: :date\n🕐 Time: :time\n\nWould you like to confirm this appointment?\n\n1️⃣ Confirm ✅\n2️⃣ Change ✏️\n3️⃣ Cancel ❌",
        'keep_time' => "🎉 Perfect:spaced!\n\nYour previous time is still available.\n\nHere is your new appointment:\n\n📅 :date\n🕐 Time: :time\n💈 Service: :service\n👤 Professional: :staff\n\nWhat would you like to do?\n\n1️⃣ Confirm the appointment\n2️⃣ Change again",
        'time_done' => "👍 Perfect:spaced!\n\nYou chose a new time.\n\nHere is the summary of your appointment:\n\n📅 Date: :date\n🕒 Time: :time\n💼 Service: :service\n👤 Professional: :staff\n\nPlease check this information.\n\n1️⃣ Confirm the appointment\n2️⃣ Change again",
        'staff_busy' => "❌ :staff is not available at :time on :date.\n\nHere are the time slots still available:\n\n:slots\n\n💬 Reply with the number or directly with the time that suits you.",
        'no_availability_staff' => "Great 👍\n\nUnfortunately, :staff has no availability left on :date.\n\nWould you like to see the availability for the coming days?\n\nSimply reply:\n\n1️⃣ Yes\n2️⃣ No",
        'staff_unavailable' => "😔 Sorry:name,\n\n:reason\n\n🏢 :business\n\nHere are the next availabilities of :staff:\n\n:suggestions\n\n💬 Simply reply with the day that suits you.",
        'reason_closed' => 'There is no availability on :date.',
        'reason_none_left' => ':staff has no availability left on :date.',
        'nothing_found' => "😕 I could not find any availability soon.\n\nPlease contact :business directly.",
        'done' => "✅ Your appointment has been updated and confirmed.\n\n👤 Person: :name\n\n💈 Service: :service\n💰 Price: :price\n\n👤 Professional: :staff\n\n📅 Appointment: :date\n🕐 Time: :time\n\nSee you soon 👋",
        'kept' => "✅ Your appointment is kept.\n\n📅 Date: :date\n🕒 Time: :time\n💼 Service: :service\n👤 Professional: :staff\n\nWe look forward to seeing you at :business.\n\nSee you soon 😊",
    ],

    'cancel' => [
        'none' => "❌ I could not find any upcoming appointment in your name.\n\nIf you would like to book a new appointment, just send us a message.",
        'choose' => "📅 *I found several appointments.*\n\n:lines\n\nWhich one would you like to cancel?\n\nSimply reply with the appointment number.",
        'choose_line' => ":number *Appointment :index*\n📅 :date\n🕐 :time\n💼 :service\n👤 :staff",
        'invalid_appointment' => "The choice is not valid.\n\nPlease reply with the number of the appointment you want to cancel.",
        'confirm' => "⚠️ *Cancellation confirmation*\n\nYou want to cancel this appointment:\n\n📅 Date: :date\n🕐 Time: :start - :end\n💼 Service: :service\n👤 Professional: :staff\n\n*Do you confirm the cancellation?*\n\n1️⃣ Yes, cancel my appointment ❌\n2️⃣ No, keep my appointment ✅",
        'done' => "✅ Your appointment has been cancelled.\n\n📅 :date\n🕒 :time\n💇 :service\n👤 :staff\n\nThis slot is now available for a new booking.\n\nThank you for your trust.\n\nIf you would like to book a new appointment, just send us a message.",
        'too_late' => "⏰ This appointment can no longer be cancelled online.\n\nPlease contact :business directly.",
    ],

    'menu' => [
        'greeting_new' => "Hello:spaced 👋\n\nWelcome to :business.\n\nWhat would you like to do?\n\n1️⃣ Book an appointment\n2️⃣ Ask a question",
        'greeting_existing' => "Hello:spaced 👋\n\nYou have an appointment scheduled:\n\n💈 :service:staff_line\n📅 :date\n🕐 :time_h\n\nWhat would you like to do?\n\n1️⃣ Confirm my attendance ✅\n2️⃣ Change my appointment ✏️\n3️⃣ Cancel my appointment ❌\n4️⃣ Book a new appointment ➕\n5️⃣ Ask a question",
        'existing_appointment' => "ℹ️ You already have an appointment scheduled.\n\n👤 Person: :name\n💈 Service: :service\n👤 Professional: :staff\n📅 Appointment: :date\n🕐 Time: :time\n\nWhat would you like to do?\n\n1️⃣ Change this appointment ✏️\n2️⃣ Book a new appointment ➕\n3️⃣ Cancel ❌",
        'with_staff' => '👤 :staff',
        'clarify' => "I didn't quite understand your request 😊\n\nWhat would you like to do?\n\n1️⃣ Book an appointment\n2️⃣ Change an appointment\n3️⃣ Cancel an appointment\n4️⃣ Ask a question\n5️⃣ Talk to someone",
        'handoff' => "Of course 🙏\n\nI am passing your request on to the :business team. Someone will reply as soon as possible.",
        'presence_confirmed' => "✅ Attendance confirmed\n\nHello :name,\n\nThank you, your attendance has been confirmed.\n\n📅 Date: :date\n🕒 Time: :time\n💼 Service: :service\n👤 Professional: :staff\n\n🏢 :business\n\nWe look forward to seeing you. See you soon!",
        'reminder_invalid' => "I didn't understand your reply.\n\n1️⃣ Confirm my attendance ✅\n2️⃣ Cancel my appointment ❌",
    ],

    'faq' => [
        'ask' => "Of course 😊\n\nWhat is your question?",
        'menu' => "What would you like to do?\n\n1️⃣ Book an appointment\n2️⃣ Ask a question",
        'unavailable' => "I don't have this information right now 🙏\n\nPlease contact :business directly.",
    ],

    'names' => [
        'weekdays' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
        'months' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
    ],

    'booking_note' => 'Booked via WhatsApp for :name',
    'service_change_reason' => 'Service change requested by the customer (WhatsApp)',
    'cancel_reason' => 'Cancelled by the customer (WhatsApp)',

    'keywords' => [
        'weekdays' => ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'],
    ],
];
