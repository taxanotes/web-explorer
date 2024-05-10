import requests
from bs4 import BeautifulSoup

url = 'https://oxylabs.io/blog'
response = requests.get(url)
soup = BeautifulSoup(response.text, 'html.parser')
print(soup.title)

