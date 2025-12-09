# Installing pyairbnb Without Admin Permissions

If you don't have admin/sudo permissions on your server, here are several ways to install pyairbnb:

## Method 1: User Installation (Recommended - No Admin Required)

Install pyairbnb in your user directory:

```bash
pip install --user pyairbnb
```

This installs pyairbnb in `~/.local/lib/python3.x/site-packages/` and doesn't require admin permissions.

**Verify installation:**
```bash
python3 -c "import pyairbnb; print('pyairbnb installed successfully')"
```

## Method 2: Local Installation in Plugin Directory

If you can't use pip at all, you can download and extract pyairbnb manually:

1. **Download pyairbnb source:**
   ```bash
   cd /path/to/wordpress/wp-content/plugins/airbnb-analyzer/
   wget https://github.com/johnbalvin/pyairbnb/archive/refs/heads/main.zip
   unzip main.zip
   mv pyairbnb-main pyairbnb
   ```

   Or if you have git access:
   ```bash
   cd /path/to/wordpress/wp-content/plugins/airbnb-analyzer/
   git clone https://github.com/johnbalvin/pyairbnb.git
   ```

2. **Install dependencies manually:**
   The pyairbnb library may have dependencies. Check the requirements:
   ```bash
   cat pyairbnb/requirements.txt
   ```
   
   Install dependencies in user space:
   ```bash
   pip install --user -r pyairbnb/requirements.txt
   ```

## Method 3: Virtual Environment (If Supported)

If your hosting allows virtual environments:

1. **Create a virtual environment:**
   ```bash
   cd /path/to/wordpress/wp-content/plugins/airbnb-analyzer/
   python3 -m venv venv
   source venv/bin/activate  # On Windows: venv\Scripts\activate
   ```

2. **Install pyairbnb:**
   ```bash
   pip install pyairbnb
   ```

3. **Update Python path in plugin settings:**
   Use the full path to the virtual environment's Python:
   ```
   /path/to/wordpress/wp-content/plugins/airbnb-analyzer/venv/bin/python
   ```

## Method 4: Download and Include Dependencies

If you have access to download files but can't run pip:

1. **Download pyairbnb and its dependencies:**
   - Visit: https://pypi.org/project/pyairbnb/
   - Download the wheel file (.whl) or source distribution
   - Download all dependencies listed in requirements

2. **Extract to plugin directory:**
   ```bash
   cd /path/to/wordpress/wp-content/plugins/airbnb-analyzer/
   mkdir pyairbnb
   # Extract downloaded files here
   ```

3. **Update Python script path:**
   The script will automatically detect pyairbnb in the plugin directory.

## Method 5: Contact Hosting Provider

If none of the above work, contact your hosting provider and ask them to:
- Install pyairbnb for your user account
- Or provide instructions for installing Python packages in your hosting environment

## Testing the Installation

After installation, test it:

```bash
python3 -c "import pyairbnb; print('Success!')"
```

Or test with a real URL:
```bash
python3 pyairbnb-scraper.py "https://www.airbnb.com/rooms/12345678"
```

## Troubleshooting

### "Module not found" error
- Check Python path in plugin settings matches your Python installation
- Verify pyairbnb is installed: `python3 -c "import pyairbnb"`
- Check file permissions on the pyairbnb directory

### "Permission denied" error
- Use `--user` flag with pip: `pip install --user pyairbnb`
- Check directory permissions in your plugin folder

### "pip command not found"
- Try `python3 -m pip` instead of just `pip`
- Or use full path: `/usr/bin/python3 -m pip install --user pyairbnb`

## Recommended Approach

For most shared hosting environments, **Method 1 (user installation)** is the easiest:

```bash
python3 -m pip install --user pyairbnb
```

This works on 99% of shared hosting providers without requiring admin access.

