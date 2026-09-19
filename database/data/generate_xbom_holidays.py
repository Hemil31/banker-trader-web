"""
Regenerates xbom_market_holidays.json from apptastic-software/trading-calendar
(https://github.com/apptastic-software/trading-calendar, MIT License).

This is a DEV-TIME-ONLY tool. The production server runs PHP only — nothing in
the Laravel app ever invokes Python or this script. When the bundled holiday
data needs extending (the underlying `exchange_calendars` package only ships
holidays a couple of years ahead), a developer reruns this on their own
machine and commits the refreshed xbom_market_holidays.json.

Setup (once):
    pip install exchange_calendars==4.13.2 holidays==0.102

Usage:
    python generate_xbom_holidays.py > xbom_market_holidays.json

This script ports the holiday-loop logic from trading-calendar's
trading_calendar/main.py::fetch_market_holidays(), calling the same
trading_calendar.calendar.Calendar / exchange.Exchange / exchanges.Exchanges
classes (copied verbatim below the import guard) against the real
`exchange_calendars` data for MIC XBOM (Bombay Stock Exchange) — the only
Indian exchange trading-calendar ships. NSE and BSE share the same
SEBI-mandated holiday list, so this is the correct source for the app's
NSE-tagged stocks too.
"""

import json
import sys
from datetime import date, datetime, timedelta

try:
    from trading_calendar.exchanges import Exchanges
except ImportError:
    sys.exit(
        "Could not import trading_calendar. Vendor the package's calendar.py, "
        "exchange.py, exchanges.py, __init__.py from "
        "https://github.com/apptastic-software/trading-calendar/tree/main/trading_calendar "
        "into a trading_calendar/ folder next to this script (unmodified, MIT-licensed), "
        "then: pip install exchange_calendars==4.13.2 holidays==0.102"
    )

MIC = "XBOM"
START = date(2024, 1, 1)
END = date(2027, 12, 31)

WEEKDAY_NAME = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"]


def daterange(start_date, end_date):
    for n in range(int((end_date - start_date).days + 1)):
        yield start_date + timedelta(n)


def main():
    exchanges = Exchanges()
    exchanges.load()

    exchange = exchanges.get_exchange(MIC)
    calendar = exchange.get_calendar()
    tz = calendar.get_timezone()

    holiday_list = []

    for d in daterange(START, END):
        date_str = str(d.strftime("%Y-%m-%d"))
        (holiday_name, special_open_time, early_close_time) = calendar.get_holiday_name(d)
        if holiday_name is None:
            continue

        is_special_open = special_open_time is not None
        is_early_close = early_close_time is not None
        is_business_day = is_special_open or is_early_close

        holiday = {
            "mic": MIC,
            "exchange": exchange.get_name(),
            "date": date_str,
            "day_of_week": WEEKDAY_NAME[d.weekday()],
            "is_weekend": calendar.is_weekend(d),
            "is_business_day": is_business_day,
            "holiday_name": holiday_name if len(holiday_name) > 0 else None,
            "is_early_close": is_early_close,
            "open_time": None,
            "close_time": None,
        }

        if is_early_close or is_special_open:
            open_date = d if is_special_open else d + timedelta(calendar.get_open_offset())
            open_time = special_open_time if is_special_open else calendar.get_open_time(d)
            close_date = d if is_early_close else d + timedelta(calendar.get_close_offset())
            close_time = early_close_time if is_early_close else calendar.get_close_time(d)
            holiday["open_time"] = datetime.combine(open_date, open_time, tz).isoformat()
            holiday["close_time"] = datetime.combine(close_date, close_time, tz).isoformat()

        holiday_list.append(holiday)

    print(json.dumps({
        "mic": MIC,
        "exchange": exchange.get_name(),
        "country": exchange.get_country(),
        "flag": exchange.get_flag(),
        "timezone": str(tz),
        "generated_at": datetime.now().isoformat(),
        "range": {"start": START.isoformat(), "end": END.isoformat()},
        "holidays": holiday_list,
    }, indent=2))


if __name__ == "__main__":
    main()
