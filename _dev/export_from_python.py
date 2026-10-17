"""One-time export: turn the old Python generator (demir-home/build_site.py) into the PHP site's content file and theme files.
Runs the old build in a temporary folder to read its data, then writes:
  app/content.default.json  - every editable text, list, photo and setting (plain text, no HTML escaping)
  app/theme/style.html      - the site CSS (<style> block)
  app/theme/script.html     - the site script (<script> block) with __FORM_TO__/__SITE__/__GA4__ placeholders
  app/theme/icons.json      - line icons (name -> SVG path data)
  app/theme/pipes.svg       - hero/page-head pipe drawing
Usage: python3 _dev/export_from_python.py /home/claude/demir-home"""
import json, os, re, shutil, subprocess, sys, tempfile

SRC = sys.argv[1].rstrip("/") if len(sys.argv) > 1 else "/home/claude/demir-home"
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
tmp = tempfile.mkdtemp(prefix="dm-export-")
subprocess.run(f"cd {SRC} && tar --exclude='./dist' --exclude='*.zip' --exclude='./demir-photos-wordpress' -cf - . | (cd {tmp} && tar -xf -)", shell=True, check=True)
code = open(os.path.join(tmp, "build_site.py"), encoding="utf-8").read().replace(f'OUT="{SRC}"', f'OUT="{tmp}"')
G = {"__name__": "dm_export"}
cwd = os.getcwd(); os.chdir(tmp)
import contextlib, io
with contextlib.redirect_stdout(io.StringIO()):
    exec(compile(code, "build_site.py", "exec"), G)
os.chdir(cwd)
raw_src = open(os.path.join(SRC, "build_site.py"), encoding="utf-8").read()


def unesc(s):
    """Old templates wrote some texts pre-escaped (&amp;); the content file stores plain text."""
    return s.replace("&amp;", "&")


def img(key, alt=None, pos=None):
    """Photo object for the content file. 'file' is the real file (webp/jpg/png) or "" when the photo slot is empty."""
    f = G["photo"](key) or ""
    ph = G["PH"].get(key, ("", "50% 50%"))
    return {"file": f, "alt": unesc(alt if alt is not None else ph[0]), "pos": pos if pos is not None else (ph[1] or "50% 50%"), "key": key}


S = G["SERVICES"]
services = []
for slug, name, icon, em, short, intro, incl, signs, close in S:
    key = G["SVC_KEY"].get(slug, f"{slug}-toronto")
    services.append({"slug": slug, "name": name, "icon": icon, "emergency": bool(em), "short": short, "intro": intro,
                     "included": incl, "signs": signs, "closing": close,
                     "image": img(key, G["PH"].get(key, (f"{name} in Toronto", ""))[0]),
                     "seo_title": "", "seo_description": ""})

cities = []
for c in G["CITIES"]:
    reg, blurb, hoods = G["CITYDATA"][c]
    slug = G["cslug"](c)
    la, lo = G["GEO"][c]
    cities.append({"name": c, "slug": slug, "region": reg, "blurb": blurb, "neighbourhoods": hoods, "lat": la, "lng": lo,
                   "image": img(f"{slug}-on"), "seo_title": "", "seo_description": ""})

faq_items = [{"q": q, "a": a} for q, a in G["FAQS"]]
faq_cats = [{"key": k, "name": unesc(n), "items": [{"q": q, "a": a} for q, a in items]} for k, n, items in G["FAQCATS"]]

content = {
    "version": 1,
    "site": {
        "url": G["SITE"], "lang": "en-CA", "og_locale": "en_CA", "geo_region": "CA-ON", "geo_placename": "Toronto",
        "legal_name": "Demir Mechanical & Construction Ltd.",
        "schema_type": "Plumber", "price_range": "$$", "slogan": "Quality. Integrity. Performance.",
        "schema_image": "assets/favicon-512.png", "og_image": "assets/favicon-512.png",
        "theme_color": "#ffffff",
        "form_to": G["FORM_TO"],
    },
    "contact": {
        "phone": G["PHONE"], "email": G["EMAIL"],
        "street": "67 Conley Street", "city": "Thornhill", "region": "ON", "region_long": "Ontario", "postal": "L4J 2X4", "country": "CA",
        "address_short": "67 Conley St, Thornhill, ON",
        "lat": 43.803, "lng": -79.423,
        "hours_short": "Mon–Fri 8AM–6PM · Emergencies 24/7",
        "hours_line1": "Mon–Fri 8:00 AM – 6:00 PM", "hours_line2": "Emergency service 24/7",
        "open_days": ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"], "opens": "08:00", "closes": "18:00",
    },
    "links": {"whatsapp": G["WHATSAPP"], "facebook": G["SOCIAL"]["facebook"], "instagram": G["SOCIAL"]["instagram"],
              "google": G["SOCIAL"]["google"], "ga4": G["GA4_ID"], "google_site_verification": ""},
    "whatsapp": {"general": "Hi Demir Mechanical, I'd like to ask about a plumbing job.",
                 "service": "Hi Demir Mechanical, I have a question about {service}.",
                 "city": "Hi Demir Mechanical, I need a plumber in {city}.",
                 "button": "WhatsApp", "menu_label": "WhatsApp", "menu_text": "Message us", "footer_text": "WhatsApp us",
                 "contact_label": "WhatsApp", "contact_text": "Message us on WhatsApp"},
    "branding": {
        "header_logo_png": G["MARK_PNG"], "header_logo_webp": G["MARK_WEBP"], "header_logo_ratio": 2.105,
        "header_logo_alt": unesc(G["LOGO_ALT"]),
        "footer_logo": G["LOGO"], "footer_logo_alt": "Demir Mechanical & Construction – Quality. Integrity. Performance.",
        "footer_logo_w": 208, "footer_logo_h": 116,
        "favicon_32": "assets/favicon-32.png", "favicon_48": "assets/favicon-48.png", "apple_touch_icon": "assets/apple-touch-icon.png",
        "watermark": "assets/dm-mark.png",
    },
    "header": {
        "util_left": "Licensed & Insured · Serving Toronto & the GTA",
        "emergency_label": "24/7 Emergency",
        "cta_label": "Book Service", "cta_link": "contact.html",
        "services_all": "View all services", "areas_all": "All service areas",
        "menu": [
            {"type": "home", "label": "Home", "show": True},
            {"type": "services", "label": "Services", "show": True},
            {"type": "areas", "label": "Service Areas", "show": True},
            {"type": "faq", "label": "FAQ", "show": True},
            {"type": "about", "label": "About", "show": True},
            {"type": "contact", "label": "Contact", "show": True},
        ],
        "drawer_email_label": "Email", "drawer_address_label": "Address",
    },
    "footer": {
        "about": "Licensed and insured plumbing & mechanical contractor serving homeowners, businesses, and property managers across Toronto and the Greater Toronto Area.",
        "emergency_label": "24/7 Emergency line",
        "services_title": "Services", "areas_title": "Service Areas", "areas_all": "All areas →", "contact_title": "Contact",
        "areas_count": 12,
        "copyright": "Demir Mechanical & Construction Ltd. All rights reserved.",
        "bottom_right": "Licensed & Insured · Thornhill, Ontario",
        "nav": [{"label": "Home", "link": "index.html"}, {"label": "Services", "link": "services.html"}, {"label": "Service Areas", "link": "service-areas.html"},
                {"label": "FAQ", "link": "faq.html"}, {"label": "About", "link": "about.html"}, {"label": "Contact", "link": "contact.html"}],
        "mbar_call": "Call Now", "mbar_quote": "Free Quote",
    },
    "blocks": {
        "emergency": {"title": "Plumbing emergency? We're on call 24/7.", "text": "Burst pipe, flooding, sewer backup or no hot water — call now and speak with a technician.", "button": "Call {phone}"},
        "cta": {"eyebrow": "Get started", "title": "Need a licensed plumber or mechanical contractor in the GTA?",
                "text": "Free estimates on installations and renovations. Emergency service around the clock.",
                "phone_label": "Call us 24/7", "button": "Request a Free Quote"},
        "process": [
            {"icon": "phone", "title": "Call or book online", "text": "Tell us what's going on — by phone or the request form. Emergencies are answered 24/7."},
            {"icon": "clip", "title": "Diagnose & quote", "text": "A licensed technician inspects the problem and gives you an upfront price before work begins."},
            {"icon": "wrench", "title": "Fix it right", "text": "We complete the work to Ontario code with quality parts, the first time."},
            {"icon": "spark", "title": "Clean up & follow up", "text": "We leave your space clean and stand behind the work after we're gone."},
        ],
        "process_eyebrow": "How It Works", "process_title": "Straightforward Service, Start to Finish",
        "trust": [
            {"icon": "shield", "title": "Licensed & Insured", "text": "Bonded, to Ontario code"},
            {"icon": "clock", "title": "24/7 Emergency", "text": "Nights, weekends & holidays"},
            {"icon": "dollar", "title": "Upfront Pricing", "text": "Quoted before we start"},
            {"icon": "clip", "title": "Free Estimates", "text": "On installs & renovations"},
        ],
        "problems_title": "Common problems we fix",
        "problems": [{"icon": k, "text": t} for k, t in G["PROBLEMS"]],
        "work_eyebrow": "What We Do", "work_title": "Plumbing & Mechanical Services in Action",
        "quote_button": "Get a Free Quote",
        "side_button": "Request Service",
        "form_error": "Sorry, your request could not be sent. Please call us at {phone}.",
        "form_service_other": "Emergency — other",
    },
    "founder": {
        "name": "Barış Demir", "role": "Co-Founder · Thornhill, Ontario", "eyebrow": "Meet the Founder",
        "quote": "Every job should be done the way I'd want it done in my own home — properly, cleanly, and at a fair price.",
        "bio": "Barış founded Demir Mechanical & Construction after years of hands-on work across residential, commercial, and industrial projects in the Greater Toronto Area. He built the company around a simple standard: honest quotes, quality workmanship, and a team that answers the phone when you need it.",
        "points": ["Personally oversees projects from quote to clean-up", "Licensed, bonded & fully insured team", "Straight answers and upfront pricing — no surprises"],
        "badge_title": "Co-Founder", "badge_text": "Demir Mechanical & Construction",
        "initials": "BD",
        "photo": img("baris-demir-co-founder-demir-mechanical", "Barış Demir, Co-Founder of Demir Mechanical & Construction"),
        "button_home": "Our Story", "button_about": "Get a Free Quote",
    },
    "pages": {
        "home": {
            "seo_title": "Toronto Plumber & Mechanical Contractor | Demir Mechanical",
            "seo_description": "Licensed Toronto plumber & mechanical contractor. 24/7 emergency plumbing, drains, water heaters, sewer repair and riser renovations across the GTA.",
            "hero_image": img(G["HERO_KEY"], G["HERO_ALT"]),
            "badge": "Technicians available 24/7", "badge_more": " across the GTA",
            "title_before": "Toronto's Trusted ", "title_highlight": "Plumbing & Mechanical", "title_after": " Contractor",
            "lead": "From emergency repairs to full mechanical room renovations, Demir Mechanical & Construction delivers licensed, dependable service for homes, businesses, and multi-unit buildings across Toronto and the Greater Toronto Area.",
            "lead_mobile": "Licensed, insured plumbers for homes, businesses and condo buildings across Toronto and the GTA.",
            "tiles_title": "What do you need help with?",
            "tiles": [{"icon": "alert", "label": "Emergency repair", "link": "service-and-repairs.html"},
                      {"icon": "drain", "label": "Drain cleaning", "link": "drain-cleaning.html"},
                      {"icon": "heater", "label": "Water heaters", "link": "water-heaters.html"},
                      {"icon": "sewer", "label": "Sewer repair", "link": "sewer-repair.html"}],
            "tiles_all": "See all {count} services",
            "proof": [{"icon": "shield", "title": "Licensed", "text": "& fully insured"},
                      {"icon": "clock", "title": "24/7", "text": "Emergency service"},
                      {"icon": "pin", "title": "GTA-wide", "text": "Based in Thornhill"}],
            "quote_title": "Request Service", "quote_text": "Tell us what you need — we'll call you back fast.",
            "quote_button": "Request Service", "quote_note": "For emergencies, call {phone}",
            "quote_sent_title": "Request received", "quote_sent_text": "Thanks! We'll call you back shortly at the number you provided.",
            "services_eyebrow": "What We Do", "services_title": "Plumbing & Mechanical Services in Toronto",
            "services_text": "Residential and commercial plumbing, drains, sewers, water heaters, and complete mechanical room and riser renovations — handled by one licensed team.",
            "about_image": img("licensed-plumber-thornhill-ontario"), "about_image_tag": "Thornhill, ON",
            "about_stat_title": "Licensed & Insured", "about_stat_text": "Work done to Ontario code",
            "about_eyebrow": "Who We Are", "about_title": "A Local Contractor Built on Quality, Integrity & Performance",
            "about_text": "Founded by Barış Demir, Demir Mechanical & Construction grew out of years of hands-on experience across residential, commercial, and industrial projects in the Greater Toronto Area. We saw too many property owners let down by rushed work and unclear pricing — so we built a company that does the opposite.",
            "values": [{"icon": "shield", "title": "Quality", "text": "Every installation and repair is done to code and built to last."},
                       {"icon": "scale", "title": "Integrity", "text": "Upfront, honest pricing — what you need, not what pads the bill."},
                       {"icon": "bolt", "title": "Performance", "text": "Fast response and systems that keep running long after we leave."}],
            "about_button": "About Demir Mechanical",
            "process_text": "No guesswork and no surprises — here's what to expect when you call Demir Mechanical.",
            "who_eyebrow": "Who We Serve", "who_title": "Homes, Businesses & Multi-Unit Buildings",
            "who_text": "Whether it's a single fixture or an entire building, we scale to the job.",
            "who": [{"icon": "home", "title": "Homeowners", "text": "Repairs, drain cleaning, water heaters, and renovation plumbing for houses and condos.",
                     "points": ["Emergency repairs 24/7", "Water heaters & toilets", "Kitchen & bath rough-ins"]},
                    {"icon": "store", "title": "Commercial", "text": "Reliable plumbing and mechanical service for offices, retail, and restaurants — scheduled around your hours.",
                     "points": ["Preventative maintenance", "Drain & sewer service", "Code-compliant upgrades"]},
                    {"icon": "building", "title": "Property Managers", "text": "Riser renovations and mechanical room upgrades for multi-unit buildings, planned to minimize resident disruption.",
                     "points": ["Riser replacement", "Mechanical room renovation", "Tenant coordination"]}],
            "areas_eyebrow": "Service Areas", "areas_title": "Licensed Plumbing & Mechanical Service Across the GTA",
            "areas_text": "Based in Thornhill, Ontario, our technicians serve homeowners, businesses, and property managers throughout Toronto and the Greater Toronto Area. Don't see your city? We cover most of the GTA — call to confirm.",
            "areas_button": "All Service Areas",
            "work_text": "From leak repairs to heating systems — the kind of work our licensed team handles every day across the GTA.",
            "work": [{"image": img("hydronic-heating-piping-gta"), "tag": "Heating systems", "label": "Featured photo"},
                     {"image": img("leaking-pipe-repair-toronto"), "tag": "Leak repair", "label": "Photo"},
                     {"image": img("main-water-valve-repair-gta"), "tag": "Water service", "label": "Photo"}],
            "reviews_eyebrow": "Reviews", "reviews_title": "What Our Customers Say",
            "reviews_text": "Reviews from homeowners and property managers across Toronto and the GTA.",
            "faq_eyebrow": "FAQ", "faq_title": "Frequently Asked Questions",
            "faq_text": "Quick answers about our plumbing and mechanical services in Toronto and the GTA.", "faq_button": "See all FAQs",
            "suppliers_eyebrow": "Suppliers", "suppliers_title": "Trusted Suppliers We Work With",
        },
        "about": {
            "seo_title": "About Us | Demir Mechanical & Construction — Toronto",
            "seo_description": "About Demir Mechanical & Construction — a locally owned, licensed plumbing and mechanical contractor serving Toronto and the GTA. Founded by Barış Demir.",
            "title": "About Demir Mechanical & Construction",
            "lead": "A locally owned Toronto contractor delivering licensed plumbing and mechanical service across the GTA — built on quality, integrity, and performance.",
            "story_eyebrow": "Our Story", "story_title": "Toronto's Trusted Plumbing & Mechanical Experts",
            "story": ["Founded by Barış Demir, Demir Mechanical & Construction grew out of years of hands-on experience across residential, commercial, and industrial projects throughout the Greater Toronto Area. We saw too many property owners let down by rushed work and unclear pricing — so we built a company that does the opposite: honest quotes, clean workmanship, and service that stands behind every job.",
                      "Today we're proud to be a locally owned and operated contractor that homeowners, businesses, and property managers across Toronto rely on — 24/7. From a single emergency repair to a full mechanical room renovation, we bring the same standards to every job."],
            "button_1": "Get a Free Quote", "button_2": "Our Services",
            "image": img("plumbing-heating-technician-toronto"), "image_tag": "Licensed & insured",
            "stat_title": "Thornhill, Ontario", "stat_text": "Serving the whole GTA",
            "promise_eyebrow": "Our Promise", "promise_title": "Quality. Integrity. Performance.",
            "promise_text": "Three words on our logo — and the standard we hold every job to.",
            "promise": [{"icon": "shield", "title": "Quality", "text": "We don't cut corners. Every installation and repair is done to Ontario code with quality parts, and built to last."},
                        {"icon": "scale", "title": "Integrity", "text": "Upfront, honest pricing with no surprises. We tell you what you actually need — not what pads the bill."},
                        {"icon": "bolt", "title": "Performance", "text": "Fast response, reliable results, and systems that keep running long after we've packed up."}],
            "process_eyebrow": "How We Work", "process_title": "What to Expect When You Call",
        },
        "services": {
            "seo_title": "Plumbing & Mechanical Services Toronto | Demir",
            "seo_description": "Plumbing & mechanical services in Toronto: repairs, drain cleaning, water heaters, sewer repair, toilets, riser and mechanical room renovation.",
            "title": "Plumbing & Mechanical Services in Toronto",
            "lead": "From emergency repairs to full mechanical room renovations — dependable, licensed service for homes, businesses, and multi-unit buildings across Toronto and the GTA.",
            "work": [{"image": img("radiant-heating-manifold-gta"), "tag": "Radiant heating", "label": "Featured photo"},
                     {"image": img("kitchen-sink-plumbing-toronto"), "tag": "Kitchen plumbing", "label": "Photo"},
                     {"image": img("bathroom-sink-drain-repair-toronto"), "tag": "Bathroom plumbing", "label": "Photo"}],
        },
        "service_template": {
            "seo_title": "{service} Toronto & GTA | Demir Mechanical",
            "seo_description": "{service} in Toronto & the GTA by Demir Mechanical. Licensed & insured, free estimates. Call {phone}.",
            "title": "{service} in Toronto & the GTA",
            "lead_end_emergency": "Licensed, insured, and available 24/7.", "lead_end_scheduled": "Licensed, insured, and available on your schedule.",
            "photo_label": "Add a {service_lower} photo",
            "included_title": "What's Included", "signs_title": "Signs You Need {service}",
            "area_title": "Serving Toronto & the GTA",
            "area_text": "Our licensed technicians serve Toronto, North York, Scarborough, Etobicoke, Vaughan, Markham, Richmond Hill and the wider GTA. We arrive prepared, work clean, and stand behind every job.",
            "faq_title": "Common Questions",
            "faq": [{"q": "How much does {service_lower} cost in Toronto?", "a": "Every job is different, so we provide an upfront quote after assessing the work. For installations and renovations, estimates are free. Call {phone} to book an assessment."},
                    {"q": "Do you offer emergency {service_lower}?", "a": "Yes — urgent plumbing issues are handled 24/7 across Toronto and the GTA.", "a_scheduled": "This service is usually scheduled, but for anything urgent our team is on call 24/7."},
                    {"q": "Which areas do you serve?", "a": "Toronto, North York, Scarborough, Etobicoke, Vaughan, Markham, Richmond Hill, Mississauga, Brampton and most of the GTA."}],
            "side_title": "Need {service_lower}?", "side_text_emergency": "Speak with a licensed technician — available 24/7.",
            "side_text_scheduled": "Speak with a licensed technician — free estimates available.",
            "all_title": "All Services", "schema_areas": 10, "breadcrumb": "Services",
        },
        "contact": {
            "seo_title": "Contact | Demir Mechanical — Toronto Plumber",
            "seo_description": "Call +1 (647) 919-3677 or request a free quote. Licensed 24/7 plumbing & mechanical service across Toronto and the GTA.",
            "title": "Contact Demir Mechanical",
            "lead": "Have a question, need a quote, or dealing with an emergency? We're here to help — 24/7 across Toronto and the GTA.",
            "eyebrow": "Get in Touch", "info_title": "Talk to a Licensed Technician",
            "phone_label": "Phone · 24/7 emergency", "email_label": "Email", "address_label": "Address", "hours_label": "Hours",
            "form_title": "Request a Free Quote", "form_text": "Fill out the form and we'll get back to you as soon as possible.",
            "form_button": "Send Request", "sent_title": "Request received", "sent_text": "Thanks! We'll get back to you as soon as possible.",
            "map_title": "Serving Toronto & the GTA", "map_text": "Based in Thornhill, Ontario · 67 Conley Street",
            "faq_eyebrow": "FAQ", "faq_title": "Frequently Asked Questions",
        },
        "faq": {
            "seo_title": "Plumbing FAQ | Demir Mechanical — Toronto & GTA",
            "seo_description": "Answers to common plumbing questions in Toronto: emergencies, pricing, estimates, water heaters, drains, backwater valves and riser renovations.",
            "title": "Plumbing FAQ — Toronto & the GTA",
            "lead": "Straight answers to the questions we hear most — emergencies, pricing, services and building projects. Can't find yours? Call us any time.",
            "side_title": "Still have a question?", "side_text": "Speak directly with a licensed technician — we're happy to help.",
            "popular_title": "Popular Services", "popular_count": 6,
        },
        "areas": {
            "seo_title": "Service Areas | Plumber Toronto & GTA | Demir Mechanical",
            "seo_description": "Demir Mechanical serves Toronto, North York, Scarborough, Vaughan, Markham, Richmond Hill, Mississauga, Brampton, Oakville and more of the GTA.",
            "title": "Service Areas — Toronto & the GTA",
            "lead": "Based in Thornhill, Ontario, Demir Mechanical & Construction serves homes, businesses and buildings across Toronto, York, Peel, Halton and Durham regions.",
            "eyebrow": "Where We Work", "list_title": "Find Your City",
            "list_text": "Choose your area to see local services, neighbourhoods and answers. Don't see your city? Call — we cover most of the GTA.",
            "regions": G["REG_ORDER"],
            "map_title": "{street}, {city}, {region}", "map_text": "Our home base — minutes from Highway 7 & 407",
        },
        "city_template": {
            "seo_title": "Plumber in {city}, ON | 24/7 Service | Demir Mechanical", "seo_title_short": "Plumber in {city}, ON | Demir Mechanical",
            "seo_description": "Licensed plumber in {city}, Ontario. 24/7 emergency plumbing, drain cleaning, water heaters & sewer repair. Free estimates — call {phone}.",
            "title": "Plumber in {city}, Ontario",
            "lead": "Licensed, insured plumbing and mechanical service in {city} — emergency repairs 24/7, drain cleaning, water heaters, sewer repair and more.",
            "photo_label": "Add a {city} photo",
            "intro": "Looking for a reliable plumber in {city}? Demir Mechanical & Construction provides licensed plumbing and mechanical service to homeowners, businesses and property managers {where}.",
            "where_toronto": "across Toronto", "where_city_of_toronto": "throughout {city} and the rest of Toronto", "where_other": "throughout {city} and {region}",
            "services_title": "Plumbing Services in {city}", "hoods_title": "Neighbourhoods We Serve in {city}",
            "why_title": "Why {city} Chooses Demir Mechanical",
            "why": ["24/7 emergency response across {city}", "Upfront pricing before any work begins", "Licensed, bonded and fully insured", "Clean, code-compliant workmanship"],
            "faq_title": "Common Questions",
            "faq": [{"q": "Do you offer emergency plumbing in {city}?", "a": "Yes. Our technicians are on call 24/7 for burst pipes, flooding, sewer backups and no-hot-water emergencies in {city}. Call {phone} any time."},
                    {"q": "How much does a plumber cost in {city}?", "a": "Every job is different, so we assess the problem and give you an upfront price before any work begins. Estimates for installations and renovations are free."},
                    {"q": "Which neighbourhoods in {city} do you serve?", "a": "We serve all of {city}, including {neighbourhoods}."}],
            "side_title": "Need a plumber in {city}?", "side_text": "Speak with a licensed technician — emergencies answered 24/7.",
            "nearby_title": "Nearby Service Areas", "nearby_link": "Plumber in {city}", "nearby_count": 5,
            "more_title": "More Areas", "more_all": "All service areas", "more_contact": "Not listed? Contact us",
        },
        "notfound": {
            "seo_title": "Page Not Found | Demir Mechanical & Construction",
            "seo_description": "This page could not be found. Browse plumbing and mechanical services, service areas across Toronto and the GTA, or contact our licensed team 24/7.",
            "title": "Sorry — that page doesn't exist",
            "lead": "The link may be old or mistyped. Here are the quickest ways to get help from our licensed plumbers.",
            "eyebrow": "Popular pages", "list_title": "Where would you like to go?",
            "button_services": "All services", "button_areas": "Service areas", "button_contact": "Contact us",
        },
    },
    "services": services,
    "cities": cities,
    "faq": {"home": faq_items, "categories": faq_cats},
    "reviews": [{"quote": q, "name": n, "where": w} for q, n, w in G["REVIEWS"]],
    "suppliers": [{"name": n, "logo": (G["sup_logo"](slug) or ""), "url": u} for n, slug, u in G["SUPPLIERS"]],
    "page_header_image": img(G["PH_KEY"], ""),
}

os.makedirs(os.path.join(ROOT, "app", "theme"), exist_ok=True)
with open(os.path.join(ROOT, "app", "content.default.json"), "w", encoding="utf-8") as f:
    json.dump(content, f, ensure_ascii=False, indent=1)
# theme: CSS, script (raw template with placeholders), icons, pipes, fixed SVGs
open(os.path.join(ROOT, "app", "theme", "style.html"), "w", encoding="utf-8").write(G["CSS"])
m = re.search(r"SCRIPTS='''(.*?)'''", raw_src, re.S)
open(os.path.join(ROOT, "app", "theme", "script.html"), "w", encoding="utf-8").write(m.group(1))
icons = dict(G["ICONS"]); icons.update({k: v for k, v in G["I"].items() if v})
json.dump(icons, open(os.path.join(ROOT, "app", "theme", "icons.json"), "w", encoding="utf-8"), ensure_ascii=False, indent=0)
open(os.path.join(ROOT, "app", "theme", "pipes.svg"), "w", encoding="utf-8").write(G["PIPES"])
json.dump({"FB": G["FB"], "IG": G["IG"], "GG": G["GG"], "FONTS": G["FONTS"]},
          open(os.path.join(ROOT, "app", "theme", "fixed.json"), "w", encoding="utf-8"), ensure_ascii=False, indent=0)
shutil.rmtree(tmp)
print("exported:", len(services), "services,", len(cities), "cities,", sum(len(c["items"]) for c in faq_cats), "FAQ page items,", len(icons), "icons")
